<?php

namespace Modules\Steamauth\Controllers\Admin;

use Modules\Steamauth\Mappers\DbLog;

class Index extends Base
{
    public function indexAction()
    {
        $this->getLayout()->getAdminHmenu()
        ->add($this->getTranslator()->trans('steamauth.menu.signinwithapi'), ['action' => 'index'])
        ->add($this->getTranslator()->trans('steamauth.menu.apikeys'), ['action' => 'index']);

        $output = [
            'consumerKey' => $this->getConfig()->get('steamauth_apikey'),
        ];

        $this->getView()->set('steamauth', $output);
    }

    public function saveAction()
    {
        if ($this->getRequest()->isPost()) {
            $oldkey = $this->getConfig()->get('steamauth_apikey');
            $newkey = $this->getRequest()->getPost('consumerKey');
            $this->getConfig()->set('steamauth_apikey', $newkey);
            $dbLog = new DbLog();

            $dbLog->dump(
                "API Key gespeichert",
                ["oldkey" => $oldkey,
                 "newkey" => $newkey  
                ]
             );

            $this->addMessage('saveSuccess');
        }

        $this->redirect(['action' => 'index']);
    }
}
