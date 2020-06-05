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
        if (! array_dot($_SESSION, 'steamauth.login') || array_dot($_SESSION, 'steamauth.login.expires') < time() ) {
            $this->addMessage('steamauth.logindenied', 'danger');
            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }

        $oauth = array_dot($_SESSION, 'steamauth.login');

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

        if (! array_dot($_SESSION, 'steamauth.login') || array_dot($_SESSION, 'steamauth.login.expires') < time()) {
            $this->addMessage('badRequest');
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
        
        $oauth = array_dot($_SESSION, 'steamauth.login');

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
                ->setProvider('steam')
                ->setOauthToken($oauth['oauth_token'])
                ->setOauthTokenSecret($oauth['oauth_token_secret'])
                ->setScreenName($oauth['screen_name'])
                ->setUserId($userId);

            $link = (new AuthProvider())->linkProviderWithUser($authProviderUser);

            if ($link === true) {
                $_SESSION['user_id'] = $userId;
                $this->addMessage('steamauth.linksuccess');
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'index']);
            }

            $this->addMessage('steamauth.linkfailed', 'danger');
            $this->redirect('/');
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
            if ($this->getRequest()->isPost()) {
                $authProvider = new AuthProvider();
                $res = $authProvider->unlinkUser('steam', currentUser()->getId());

                if ($res > 0) {
                    $this->addMessage('steamauth.unlinkedsuccessfully');
                    $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
                }

                $this->addMessage('steamauth.couldnotunlink', 'danger');
                $this->redirect('/');
            }

            $this->addMessage('steamauth.badrequest', 'danger');
            $this->redirect('/');
        }

        $this->addMessage('steamauth.notauthenticated', 'danger');
        $this->redirect('/');
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

            if (loggedIn()) {
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);

                $this->dbLog()->info(
                    "User " . currentUser()->getName() . " has an login error.",
                    [
                        'userId' => currentUser()->getId(),
                        'userName' => currentUser()->getName(),
                        'message' => $e->getMessage(),
                    ]
                    );
            }

            $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
        }
    }

    /**
     * Callback action.
     */
    public function callbackAction()
    {
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
            $existingLink = $authProvider->providerAccountIsLinked('steam', $steamUser['user_id']);

            if (loggedIn()) {
                if ($authProvider->hasProviderLinked('steam', currentUser()->getId())) {
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
                    ->setProvider('steam')
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

                $this->addMessage('linkFailed', 'danger');
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            }

            if ($existingLink === true) {
                $userId = $authProvider->getUserIdByProvider('steam', $steamUser['user_id']);

                if (is_null($userId)) {
                    $this->addMessage('couldNotFindRequestedUser');
                    $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
                }

                $_SESSION['user_id'] = $userId;

                $this->addMessage('steamauth.loginsuccess');
                $this->redirect('/');
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

            if (loggedIn()) {
                $this->redirect(['module' => 'user', 'controller' => 'panel', 'action' => 'providers']);
            } else {
                $this->redirect(['module' => 'user', 'controller' => 'login', 'action' => 'index']);
            }
        }
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
