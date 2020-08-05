<?php

namespace Modules\Steamauth\Controllers;

use Ilch\Controller\Frontend;
use Modules\Steamauth\Libs\SteamAuth as SteamOAuth;
use Modules\Steamauth\Mappers\DbLog;
use Modules\User\Mappers\AuthProvider;
use Modules\User\Mappers\AuthToken as AuthTokenMapper;
use Modules\User\Mappers\User as UserMapper;
use Modules\User\Mappers\Group;
use Modules\User\Models\AuthProviderUser;
use Modules\User\Models\AuthToken as AuthTokenModel;
use Modules\User\Models\User;
use Modules\User\Service\Password as PasswordService;
use Ilch\Validation;
use Modules\User\Mappers\CookieStolen as CookieStolenMapper;

class Auth extends Frontend
{
    /**
     * @var DbLog instance
     */
    protected $dbLog;

    /**
     * Renders the register form.
     */
    public function registAction()
    {
        $oauth = array_dot($_SESSION, 'steamauth.login');

        if (!$oauth || array_dot($_SESSION, 'steamauth.login.expires') < time() ) {
            $this->addMessage('steamauth.logindenied', 'danger');
            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }

        $this->getView()->set('rules', $this->getConfig()->get('regist_rules'));
        $this->getView()->set('user', $oauth);
    }

    /**
     * Saves the new user to the database.
     */
    public function saveAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->addMessage('badRequest');
            $this->redirect('/');
        }

        $oauth = array_dot($_SESSION, 'steamauth.login');

        if (!$oauth || array_dot($_SESSION, 'steamauth.login.expires') < time()) {
            $this->addMessage('steamauth.logindenied');
            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }

        $input = [
            'userName' => trim($this->getRequest()->getPost('userName')),
            'email' => trim($this->getRequest()->getPost('email')),
        ];

        $validation = Validation::create($input, [
            'userName' => 'required|unique:users,name',
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validation->isValid()) {
            // register user
            $registMapper = new UserMapper();
            $groupMapper = new Group();
            $userGroup = $groupMapper->getGroupById(2);
            $currentDate = new \Ilch\Date();

            $user = (new User())
                ->setName($input['userName'])
                ->setPassword((new PasswordService())->hash(PasswordService::generateSecurePassword(32)))
                ->setEmail($input['email'])
                ->setDateCreated($currentDate->format('Y-m-d H:i:s', true))
                ->addGroup($userGroup)
                ->setDateConfirmed($currentDate->format('Y-m-d H:i:s', true));

            $userId = $registMapper->save($user);

            $authProviderUser = (new AuthProviderUser())
                ->setIdentifier($oauth['user_id'])
                ->setProvider('steamauth_steam')
                ->setOauthToken($oauth['oauth_token'])
                ->setOauthTokenSecret($oauth['oauth_token_secret'])
                ->setScreenName($oauth['screen_name'])
                ->setUserId($userId);

            $link = (new AuthProvider())->linkProviderWithUser($authProviderUser);

            if ($link === true) {
                $this->login($userId);

                $this->addMessage('steamauth.linksuccess');
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'index']);
            }

            $this->addMessage('steamauth.linkfailed', 'danger');
            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }

        $this->addMessage($validation->getErrorBag()->getErrorMessages(), 'danger', true);
        $this->redirect()
            ->withInput()
            ->withErrors($validation->getErrorBag())
            ->to(['action' => 'regist']);
    }

    public function unlinkAction()
    {
        if (loggedIn()) {
            $authProvider = new AuthProvider();
            $authProviderUser = $authProvider->getLinkedProviderDetails('wargaming', currentUser()->getId());

            if ($this->getRequest()->isPost()) {
                $res = $authProvider->unlinkUser('steamauth_steam', currentUser()->getId());

                if ($res > 0) {
                    $this->addMessage('steamauth.unlinkedsuccessfully');
                    $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
                }

                $this->addMessage('steamauth.couldnotunlink', 'danger');
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            }

            $this->addMessage('steamauth.badrequest', 'danger');
            $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
        }

        $this->addMessage('steamauth.notauthenticated', 'danger');
        $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
    }

    /**
     * Initialize authentication.
     */
    public function indexAction()
    {
        $callbackUrl = $this->getLayout()->getUrl([
            'module' => 'steamauth',
            'controller' => 'auth',
            'action' => 'callback',
        ]);
    
        if ($this->getRequest()->getPost('rememberMe')) {
            $_SESSION['steamauth']['rememberMe'] = $this->getRequest()->getPost('rememberMe');
        }
        $_SESSION['steamauth']['login_redirect_url'] = $this->getRequest()->getPost('login_redirect_url');

        $auth = new SteamOAuth(
            $this->getConfig()->get('steamauth_apikey'),
            $_SERVER['SERVER_NAME'],
            $callbackUrl,
            null,
            false
            );

        try {
            $this->redirect($auth->loginUrl());
        } catch (\Exception $e) {
            $this->addMessage('steamauth.authenticationfailure', 'danger');

            if (!loggedIn()){
                $userMapper = new UserMapper();
                $currentUser = $userMapper->getDummyUser();
            }else{
                $currentUser = currentUser();
            }

            $this->dbLog()->info(
                "User " . $currentUser->getName() . " has an login error.",
                [
                    'userId' => $currentUser->getId(),
                    'userName' => $currentUser->getName(),
                    'message' => $e->getMessage(),
                ]
            );

            if (loggedIn()) {
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            }

            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }
    }

    /**
     * Callback action.
     */
    public function callbackAction()
    {
        $redirectUrl = ['module' => 'user', 'controller' => 'login', 'action' => 'index'];

        $auth = new SteamOAuth(
            $this->getConfig()->get('steamauth_apikey')
        );
        
        $steamUser = [];

        try {
            $steamUser = [
                'user_id' => $auth->steamid,
                'oauth_token' => $auth->primaryclanid,
                'screen_name' => $auth->personaname,
                'oauth_token_user' => null,
            ];

            $authProvider = new AuthProvider();
            $existingLink = $authProvider->providerAccountIsLinked('steamauth_steam', $steamUser['user_id']);

            if (loggedIn()) {
                if ($authProvider->hasProviderLinked('steamauth_steam', currentUser()->getId())) {
                    $this->dbLog()->info(
                        "User " . currentUser()->getName() . " had provider already linked.",
                        [
                            'userId' => currentUser()->getId(),
                            'userName' => currentUser()->getName(),
                            'steamAccount' => $steamUser
                        ]
                    );

                    $this->addMessage('providerAlreadyLinked', 'danger');
                    $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
                }

                if ($existingLink === true) {
                    $this->dbLog()->info(
                        "User " . currentUser()->getName() . " tried to link an already linked steam account.",
                        [
                            'userId' => currentUser()->getId(),
                            'userName' => currentUser()->getName(),
                            'steamAccount' => $steamUser
                        ]
                    );

                    $this->addMessage('accountAlreadyLinkedToDifferentUser', 'danger');
                    $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
                }

                $authProviderUser = (new AuthProviderUser())
                    ->setIdentifier($steamUser['user_id'])
                    ->setProvider('steamauth_steam')
                    ->setOauthToken($steamUser['oauth_token'])
                    ->setOauthTokenSecret($steamUser['oauth_token_user'])
                    ->setScreenName($steamUser['screen_name'])
                    ->setUserId(currentUser()->getId());

                $link = $authProvider->linkProviderWithUser($authProviderUser);

                if ($link === true) {
                    $this->dbLog()->info(
                        "User " . currentUser()->getName() . " has linked a steam account.",
                        [
                            'userId' => currentUser()->getId(),
                            'userName' => currentUser()->getName(),
                            'steamAccount' => $steamUser
                        ]
                    );

                    $this->addMessage('steamauth.linksuccess');
                    $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
                }

                $this->dbLog()->error(
                    "User " . currentUser()->getName() . " could not link his steam account.",
                    [
                        'userId' => currentUser()->getId(),
                        'userName' => currentUser()->getName(),
                        'steamAccount' => $steamUser
                    ]
                );

                $this->addMessage('steamauth.linkFailed', 'danger');
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            }

            if ($existingLink === true) {
                $userId = $authProvider->getUserIdByProvider('steamauth_steam', $steamUser['user_id']);

                $this->login($userId);
                
                if (!empty($_SESSION['steamauth']['login_redirect_url'])) {
                    $redirectUrl = $_SESSION['steamauth']['login_redirect_url'];
                    unset($_SESSION['steamauth']['login_redirect_url']);
                }

                $this->addMessage('steamauth.loginsuccess');
                $this->redirect($redirectUrl);
            }

            if ($existingLink === false && ! loggedIn() && ! $this->getConfig()->get('regist_accept')) {
                $this->addMessage('steamauth.messages.registrationNotAllowed', 'danger');
                $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
            }

            array_dot_set($_SESSION, 'steamauth.login', $steamUser);
            array_dot_set($_SESSION, 'steamauth.login.expires', strtotime('+5 minutes'));

            $this->redirect(['action' => 'regist']);
        } catch (\Exception $e) {
            $this->addMessage('steamauth.authenticationfailure', 'danger');

            if (!loggedIn()){
                $userMapper = new UserMapper();
                $currentUser = $userMapper->getDummyUser();
            }else{
                $currentUser = currentUser();
            }
            
            $this->dbLog()->info(
                "User " . $currentUser->getName() . " has an login error.",
                [
                    'userId' => $currentUser->getId(),
                    'userName' => $currentUser->getName(),
                    'message' => $e->getMessage(),
                ]
            );

            if (loggedIn()) {
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            } else {
                $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
            }
        }
    }

    /**
     * Login User and controls Remember me
     *
     * @param int $user_id
     *
     * @return bool
     */
    protected function login($user_id)
    {
        $userMapper = new UserMapper();
        $userMapper->deleteselectsdelete(($this->getConfig()->get('userdeletetime')));
        $currentUser = $userMapper->getUserById($user_id);

        if (is_null($user_id) or !$currentUser) {
            $this->addMessage('couldNotFindRequestedUser', 'danger');
            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }

        $_SESSION['user_id'] = $currentUser->getId();

        $cookieStolenMapper = new CookieStolenMapper();

        if ($cookieStolenMapper->containsCookieStolen($currentUser->getId())) {
            // The user receives a strongly worded warning that his cookie might be stolen.
            $cookieStolenMapper->deleteCookieStolen($currentUser->getId());
            $this->addMessage('cookieStolen', 'danger');
        }

        if ($_SESSION['steamauth']['rememberMe']) {
            $authTokenModel = new AuthTokenModel();

            // 9 bytes of random data (base64 encoded to 12 characters) for the selector.
            // This provides 72 bits of keyspace and therefore 236 bits of collision resistance (birthday attacks)
            $authTokenModel->setSelector(base64_encode(random_bytes(9)));
            // 33 bytes (264 bits) of randomness for the actual authenticator. This should be unpredictable in all practical scenarios.
            $authenticator = random_bytes(33);
            // SHA256 hash of the authenticator. This mitigates the risk of user impersonation following information leaks.
            $authTokenModel->setToken(hash('sha256', $authenticator));
            $authTokenModel->setUserid($currentUser->getId());
            $authTokenModel->setExpires(date('Y-m-d\TH:i:s', strtotime( '+30 days' )));

            if (PHP_VERSION_ID >= 70300) {
                setcookie('remember', $authTokenModel->getSelector().':'.base64_encode($authenticator), [
                    'expires' => strtotime('+30 days'),
                    'path' => '/',
                    'domain' => $_SERVER['SERVER_NAME'],
                    'samesite' => 'Lax',
                    'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                ]);
            } else {
                // workaround syntax to set the SameSite attribute in PHP < 7.3
                setcookie('remember', $authTokenModel->getSelector().':'.base64_encode($authenticator), strtotime('+30 days'), '/; samesite=Lax', $_SERVER['SERVER_NAME'], (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), true);
            }

            $authTokenMapper = new AuthTokenMapper();
            $authTokenMapper->addAuthToken($authTokenModel);
        }
        unset($_SESSION['steamauth']['rememberMe']);
        
        return true;
    }

    /**
     * @return DbLog
     */
    protected function dbLog()
    {
        if ($this->dbLog instanceof DbLog) {
            return $this->dbLog;
        }

        return $this->dbLog = new DbLog();
    }
}
