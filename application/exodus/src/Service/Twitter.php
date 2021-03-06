<?php
/**
 * Epixa - Exodus
 */

namespace Exodus\Service;

use HttpRequest,
    Exodus\Model\User as UserModel,
    Exodus\Collection\Friend as FriendCollection,
    Epixa\Service\Twitter\RestApi as TwitterRestApi,
    Zend_Cache as Cache,
    Zend_Session_Namespace as SessionNamespace,
    Zend_Oauth as Oauth,
    Zend_Oauth_Consumer as OauthConsumer,
    Zend_Oauth_Token_Request as RequestToken,
    Zend_Oauth_Token_Access as AccessToken,
    Zend_Http_Client as HttpClient,
    Core\Exception\RateLimitException,
    Epixa\Exception\ConfigException,
    Epixa\Exception\NotFoundException,
    LogicException,
    RuntimeException;

/**
 * Service that manages interaction with twitter api
 * 
 * @category   Module
 * @package    Exodus
 * @subpackage Service
 * @copyright  2011 epixa.com - Court Ewing
 * @license    http://github.com/epixa/Exodus/blob/master/LICENSE New BSD
 * @author     Court Ewing (court@epixa.com)
 */
class Twitter extends AbstractService
{
    const ACCESS_TOKEN_KEY  = 'accessToken';
    const REQUEST_TOKEN_KEY = 'requestToken';
    
    /**
     * @var null|string
     */
    protected $_session = null;
    
    /**
     * @var null|OauthConsumer
     */
    protected $_oauthConsumer = null;
    
    
    /**
     * Gets the session namespace for this service
     * 
     * If no session namespace is set, a new one is created with the name of 
     * this class.
     * 
     * @return SessionNamespace
     */
    public function getSession()
    {
        if ($this->_session === null) {
            $ns = new SessionNamespace(__CLASS__);
            $this->setSession($ns);
        }
        
        return $this->_session;
    }
    
    /**
     * Sets the session namespace for this service
     * 
     * @param  SessionNamespace $session
     * @return Twitter *Fluent interface*
     */
    public function setSession(SessionNamespace $session)
    {
        $this->_session = $session;
        
        return $this;
    }
    
    /**
     * Sets the service's oauth consumer
     * 
     * @param  OauthConsumer $consumer
     * @return Twitter *Fluent interface*
     */
    public function setOauthConsumer(OauthConsumer $consumer)
    {
        $this->_oauthConsumer = $consumer;
        return $this;
    }
    
    /**
     * Gets the service's oauth consumer
     * 
     * If no consumer is set, a new one is instantiated with the service's oauth
     * configuration
     * 
     * @return OauthConsumer
     * @throws ConfigException If insufficient config to create new consumer
     */
    public function getOauthConsumer()
    {
        if ($this->_oauthConsumer === null) {
            $config = $this->getConfig()->twitter;
            if (empty($config->oauth)) {
                throw new ConfigException('No oauth details configured');
            }

            $consumer = new OauthConsumer($config->oauth);
            $this->setOauthConsumer($consumer);
        }
        
        return $this->_oauthConsumer;
    }
    
    /**
     * Gets the current access token
     * 
     * @return null|AccessToken
     */
    public function getAccessToken()
    {
        $accessTokenKey = self::ACCESS_TOKEN_KEY;
        return $this->getSession()->$accessTokenKey;
    }
    
    /**
     * Sets the current access token
     * 
     * @param  AccessToken $token
     * @return Twitter *Fluent interface*
     * @throws LogicException If an authenticated session is already in place
     */
    public function setAccessToken(AccessToken $token)
    {
        if ($this->isAuthenticated()) {
            throw new LogicException('Cannot set access token because session is already authenticated');
        }
        
        $accessTokenKey = self::ACCESS_TOKEN_KEY;
        $this->getSession()->$accessTokenKey = $token;
        
        return $this;
    }
    
    /**
     * Gets the current request token
     * 
     * @return null|RequestToken
     */
    public function getRequestToken()
    {
        $requestTokenKey = self::REQUEST_TOKEN_KEY;
        return $this->getSession()->$requestTokenKey;
    }
    
    /**
     * Sets the current request token
     * 
     * @param  RequestToken $token
     * @return Twitter *Fluent interface*
     * @throws LogicException If an authenticated session is already in place
     */
    public function setRequestToken(RequestToken $token)
    {
        if ($this->isAuthenticated()) {
            throw new LogicException('Cannot set request token because session is already authenticated');
        }
        
        $requestTokenKey = self::REQUEST_TOKEN_KEY;
        $this->getSession()->$requestTokenKey = $token;
        
        return $this;
    }
    
    /**
     * Is the user currently authenticated?
     * 
     * @return boolean
     */
    public function isAuthenticated()
    {
        if (empty($this->getConfig()->twitter->oauth)) {
            return true;
        }
        
        return $this->getAccessToken() !== null ? true : false;
    }
    
    /**
     * Initiates the oauth authentication request
     * 
     * This will redirect to the provider described by this service's oauth 
     * configuration.
     * 
     * @param null|string $callbackUrl
     */
    public function beginAuthentication($callbackUrl = null)
    {
        $consumer = $this->getOauthConsumer();
        
        if ($callbackUrl !== null) {
            $consumer->setCallbackUrl($callbackUrl);
        }
        
        $token = $consumer->getRequestToken();
        $this->setRequestToken($token);
        
        $consumer->redirect();
    }
    
    /**
     * Complete the authentication request
     * 
     * An oauth access token is generated and persisted; any existing tokens are
     * destroyed.
     * 
     * @param  array $params 
     * @throws LogicException If no existing request token is available
     */
    public function completeAuthentication(array $params)
    {
        $requestToken = $this->getRequestToken();
        if ($requestToken === null) {
            throw new LogicException('Cannot complete authentication without a request token');
        }
        
        $consumer = $this->getOauthConsumer();
        
        $accessToken = $consumer->getAccessToken($params, $requestToken);
        
        $this->clearAuthentication();
        
        $this->setAccessToken($accessToken);
    }
    
    /**
     * Clears the current session details for this service
     */
    public function clearAuthentication()
    {
        $this->getSession()->unsetAll();
    }
    
    /**
     * Gets the users that the specific user follows
     * 
     * @param  string $username 
     * @return array
     */
    public function getFriendsByUsername($username)
    {
        $cache = $this->getCache();
        
        $key = sha1(serialize(array('twitter-friends-of-username' => $username)));
        
        if (($collection = $cache->load($key)) === false) {
            $config = $this->getConfig()->twitter;
            
            $collection = new FriendCollection();
            $this->_loadFriends($collection, $username);
            
            $lifetime = $config->cache->get('friends', false);
            $cache->save($collection, $key, array(), $lifetime);
        }
        
        return $collection;
    }
    
    /**
     * Creates a new, unique pauth nonce
     * 
     * @return string
     */
    public function createNonce()
    {
        return sha1(microtime() . mt_rand()) . uniqid();
    }
    
    
    /**
     * Recursively loads all of the users that the given user follows
     * 
     * @param FriendCollection $collection
     * @param string           $username
     * @param integer          $cursor
     */
    protected function _loadFriends(FriendCollection $collection, $username, $cursor = -1)
    {
        $config = $this->getConfig()->twitter;
        
        $accessToken = $this->getAccessToken();
        $twitter = new TwitterRestApi(array(
            'accessToken' => $config->oauth ? $accessToken : null
        ));
        
        $body = $twitter->user->friends(array(
            'screen_name' => $username,
            'cursor' => $cursor
        ));
        $response = $twitter->getLocalHttpClient()->getLastResponse();
        
        if ($response->getStatus() == 404) {
            throw new NotFoundException(sprintf('Twitter user `%s` was not found', $username));
        } else if ($response->getStatus() == 400) {
            throw new RateLimitException('You have exceeded the twitter rate limit');
        } else if ($response->getStatus() != 200) {
            throw new RuntimeException(sprintf('Error loading twitter user: %s', $response->getMessage()));
        }
        
        if ($body->users->user->count() > 0) {
            foreach($body->users->user as $user) {
                $friend = new UserModel();
                $friend->id = (int)$user->id;
                $friend->name = (string)$user->name;
                $friend->username = (string)$user->screen_name;
                $collection->add($friend);
            }
        }
        
        $nextCursor = (int)$body->next_cursor;
        if ($nextCursor != 0) {
            unset($response, $body);
            $this->_loadFriends($collection, $username, $nextCursor);
        }
    }
}