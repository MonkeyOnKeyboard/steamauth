<?php

namespace Modules\Steamauth\Controllers;

use Ilch\Controller\Frontend;
use Ilch\Date;
use Modules\Steamauth\Libs\SteamAuth as SteamOAuth;
use Modules\Steamauth\Mappers\DbLog;
use Modules\User\Mappers\AuthProvider;
use Modules\User\Mappers\User as UserMapper;
use Modules\User\Mappers\Group;
use Modules\User\Models\AuthProviderUser;
use Modules\User\Models\User;
use Modules\User\Service\Password as PasswordService;
use Ilch\Validation;
use Modules\User\Mappers\CookieStolen as CookieStolenMapper;

use Modules\User\Service\Remember as RememberMe;
use Modules\User\Service\Login\Result as LoginResult;

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
            $this->addMessage($this->getTranslator()->trans('steamauth.logindenied'), 'danger');
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
        $redirectUrl = '/';

        if (!$this->getRequest()->isPost()) {
            $this->addMessage($this->getTranslator()->trans('steamauth.badRequest'));
            $this->redirect('/');
        }

        $oauth = array_dot($_SESSION, 'steamauth.login');

        if (!$oauth || array_dot($_SESSION, 'steamauth.login.expires') < time()) {
            $this->addMessage($this->getTranslator()->trans('steamauth.logindenied'));
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
            $currentDate = new Date();

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
                $result  = $this->login($userId);
                if ($result->isSuccessful()) {
                    if (array_dot($_SESSION, 'steamauth.login_redirect_url')) {
                        $redirectUrl = array_dot($_SESSION, 'steamauth.login_redirect_url');
                        unset($_SESSION['steamauth']['login_redirect_url']);
                    }

                    if ($result->getError() != '') {
                        $this->addMessage($this->getTranslator()->trans('steamauth.'.$result->getError()), 'warning');
                    }

                    $this->addMessage($this->getTranslator()->trans('steamauth.linksuccess'));
                } else {
                    $this->addMessage($this->getTranslator()->trans('steamauth.'.$result->getError()), 'warning');
                    $redirectUrl = ['module' => 'user', 'controller' => 'login', 'action' => 'index'];
                }
                
                $this->redirect($redirectUrl);
                //$this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'index']);
            }

            $this->addMessage($this->getTranslator()->trans('steamauth.linkfailed'), 'danger');
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

            if ($this->getRequest()->isPost()) {
                $res = $authProvider->unlinkUser('steamauth_steam', currentUser()->getId());

                if ($res > 0) {
                    $this->addMessage($this->getTranslator()->trans('steamauth.unlinkedsuccessfully'));
                } else {
                    $this->addMessage($this->getTranslator()->trans('steamauth.couldnotunlink'), 'danger');
                }
            } else {
                $this->addMessage($this->getTranslator()->trans('steamauth.badrequest'), 'danger');
            }
        } else {
            $this->addMessage($this->getTranslator()->trans('steamauth.notauthenticated'), 'danger');
        }

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
            array_dot_set($_SESSION, 'steamauth.rememberMe', $this->getRequest()->getPost('rememberMe'));
        }
        array_dot_set($_SESSION, 'steamauth.login_redirect_url', $this->getRequest()->getPost('login_redirect_url'));

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
            $this->addMessage($this->getTranslator()->trans('steamauth.authenticationfailure'), 'danger');

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
        $redirectUrl = '/';

        $auth = new SteamOAuth(
            $this->getConfig()->get('steamauth_apikey')
        );

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

                    $this->addMessage($this->getTranslator()->trans('steamauth.providerAlreadyLinked'), 'danger');
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

                    $this->addMessage($this->getTranslator()->trans('steamauth.accountAlreadyLinkedToDifferentUser'), 'danger');
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

                    $this->addMessage($this->getTranslator()->trans('steamauth.linksuccess'));
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

                $this->addMessage($this->getTranslator()->trans('steamauth.linkFailed'), 'danger');
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            }

            if ($existingLink === true) {
                $userId = $authProvider->getUserIdByProvider('steamauth_steam', $steamUser['user_id']);

                $result  = $this->login($userId);
                if ($result->isSuccessful()) {
                    if (array_dot($_SESSION, 'steamauth.login_redirect_url')) {
                        $redirectUrl = array_dot($_SESSION, 'steamauth.login_redirect_url');
                        unset($_SESSION['steamauth']['login_redirect_url']);
                    }

                    if ($result->getError() != '') {
                        $this->addMessage($this->getTranslator()->trans('steamauth.'.$result->getError()), 'warning');
                    }

                    $this->addMessage($this->getTranslator()->trans('steamauth.loginsuccess'));
                } else {
                    $this->addMessage($this->getTranslator()->trans('steamauth.'.$result->getError()), 'warning');
                    $redirectUrl = ['module' => 'user', 'controller' => 'login', 'action' => 'index'];
                }
                
                $this->redirect($redirectUrl);
            }

            if ($existingLink === false && !loggedIn() && !$this->getConfig()->get('regist_accept')) {
                $this->addMessage($this->getTranslator()->trans('steamauth.registrationNotAllowed'), 'danger');
                $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
            }

            array_dot_set($_SESSION, 'steamauth.login', $steamUser);
            array_dot_set($_SESSION, 'steamauth.login.expires', strtotime('+5 minutes'));

            $this->redirect(['action' => 'regist']);
        } catch (\Exception $e) {
            $this->addMessage($this->getTranslator()->trans('steamauth.authenticationfailure'), 'danger');

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
     * Login User and controls Remember me & CookieStolen
     *
     * @param int $user_id
     *
     * @return LoginResult
     */
    protected function login(int $user_id): LoginResult
    {
        $userMapper = new UserMapper();
        $userMapper->deleteselectsdelete(($this->getConfig()->get('userdeletetime')));
        $currentUser = $userMapper->getUserById($user_id);

        if ($currentUser === null || !$user_id) {
            return new LoginResult(false, $currentUser, 'linkfailed');
        }

        if ($currentUser->getLocked()) {
            return new LoginResult(false, $currentUser, LoginResult::USER_LOCKED);
        }

        $_SESSION['user_id'] = $user_id;

        $selectsDelete = $currentUser->getSelectsDelete();
        if ($selectsDelete != '' && $selectsDelete != '1000-01-01 00:00:00') {
            $userMapper->selectsdelete($user_id);
            return new LoginResult(true, $currentUser, LoginResult::USER_SELECTSDELETE);
        }

        if (array_dot($_SESSION, 'steamauth.rememberMe')) {
            $rememberMe = new RememberMe();
            $rememberMe->rememberMe($user_id);
            unset($_SESSION['steamauth']['rememberMe']);
        }

        $cookieStolenMapper = new CookieStolenMapper();

        $error = null;
        if ($cookieStolenMapper->containsCookieStolen($user_id)) {
            // The user receives a strongly worded warning that his cookie might be stolen.
            $cookieStolenMapper->deleteCookieStolen($user_id);
            $error = 'cookieStolen';
        }

        return new LoginResult(true, $currentUser, $error);
    }

    /**
     * @return DbLog
     */
    protected function dbLog(): DbLog
    {
        if ($this->dbLog instanceof DbLog) {
            return $this->dbLog;
        }

        return $this->dbLog = new DbLog();
    }
}
