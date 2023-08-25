<?php

namespace Modules\Steamauth\Controllers\Admin;

use Ilch\Controller\Admin;

class Base extends Admin {
    /**
     * Init function
     */
    public function init()
    {
        $items = [
            [
                'name' => 'steamauth.menu.apikeys',
                'active' => $this->isActive('index', 'index'),
                'icon' => 'fa-solid fa-gears',
                'url' => $this->getLayout()->getUrl(['controller' => 'index', 'action' => 'index'])
            ],
            [
                'name' => 'steamauth.menu.logs',
                'active' => $this->isActive('log', 'index'),
                'icon' => 'fa-solid fa-list',
                'url' => $this->getLayout()->getUrl(['controller' => 'log', 'action' => 'index'])
            ]
        ];

        $this->getLayout()->addMenu(
            'steamauth.menu.signinwithapi',
            $items
        );
    }

    /**
     * Checks if the menu item is active
     *
     * @param $controller
     * @param $action
     *
     * @return bool
     */
    protected function isActive($controller, $action): bool
    {
        return $this->getRequest()->getControllerName() === $controller && $this->getRequest()->getActionName() === $action;
    }
}
