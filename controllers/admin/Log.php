<?php

namespace Modules\Steamauth\Controllers\Admin;

use Modules\Steamauth\Mappers\DbLog;

class Log extends Base
{
    
    public function indexAction()
    {
        $this->getLayout()->getAdminHmenu()
            ->add($this->getTranslator()->trans('steamauth.menu.signinwithapi'), ['controller' => 'index', 'action' => 'index'])
            ->add($this->getTranslator()->trans('steamauth.menu.logs'), ['action' => 'index']);

        $dbLog = new DbLog();
        $this->getView()->set('logs', $dbLog->getAll());
    }

    public function clearAction()
    {
        if (! $this->getRequest()->isPost()) {
            $this->addMessage('steamauth.methodnotallowed', 'danger');

            $this->redirect(['action' => 'index']);
        }

        $dbLog = new DbLog();

        try {
            $dbLog->clear();

            $this->addMessage('steamauth.loghasbeencleared');

            $this->redirect(['action' => 'index']);
        } catch (\Exception $e) {
            $this->addMessage('steamauth.couldnotclearlog', 'danger');

            $this->redirect(['action' => 'index']);
        }
    }

    public function deleteAction()
    {
        $dbLog = new DbLog();

        try {
            $dbLog->delete($this->getRequest()->getParam('id'));

            $this->addMessage('steamauth.logdeletedsuccessful');

            $this->redirect(['action' => 'index']);
        } catch (\Exception $e) {
            $this->addMessage('steamauth.logdeletederror', 'danger');

            $this->redirect(['action' => 'index']);
        }
    }
}
