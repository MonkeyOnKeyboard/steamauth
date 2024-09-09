<?php
/**
 * @copyright Ilch 2
 * @package ilch
 */

namespace Modules\Steamauth\Config;

use Ilch\Config\Database;

class Config extends \Ilch\Config\Install
{
    public $config = [
        'key' => 'steamauth',
        'icon_small' => 'fa-brands fa-square-steam',
        'author' => 'FAOS | MonkeyOnKeyboard',
        'hide_menu' => true,
        'version' => '1.0.7',
        'languages' => [
            'de_DE' => [
                'name' => 'Anmelden mit Steam',
                'description' => 'Erm&ouml;glicht Benutzern die Anmeldung per Steam.',
            ],
            'en_EN' => [
                'name' => 'Sign in with Steam',
                'description' => 'Allows users to sign in through Steam.',
            ],
        ],
        'ilchCore' => '2.2.0',
        'phpVersion' => '7.4'
    ];

    public function install()
    {
        if (! $this->providerExists()) {
            $this->db()
                ->insert('auth_providers')
                ->values([
                    'key' => 'steamauth_steam',
                    'name' => 'Steam',
                    'icon' => 'fa-brands fa-square-steam'
                ])
                ->execute();
        }

       $this->db()->query('
            CREATE TABLE IF NOT EXISTS `[prefix]_steamauth_log` (
              `id` int(32) unsigned NOT NULL AUTO_INCREMENT,
              `type` varchar(50) DEFAULT \'info\',
              `message` text,
              `data` text,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->db()
            ->insert('auth_providers_modules')
            ->values([
                'module' => 'steamauth',
                'provider' => 'steamauth_steam',
                'auth_controller' => 'auth',
                'auth_action' => 'index',
                'unlink_controller' => 'auth',
                'unlink_action' => 'unlink',
            ])
            ->execute();

        $databaseConfig = new Database($this->db());
        $databaseConfig->set('steamauth_apikey', '');

    }

    public function uninstall()
    {
        $this->db()
            ->delete()
            ->from('auth_providers_modules')
            ->where(['module' => 'steamauth'])
            ->execute();

        $this->db()
            ->delete()
            ->from('auth_providers')
            ->where(['key' => 'steamauth_steam'])
            ->execute();

            $this->db()->queryMulti("
            DELETE FROM `[prefix]_config` WHERE `key` = 'steamauth_apikey';
            DROP TABLE IF EXISTS `[prefix]_steamauth_log`;
            ");
    }

    public function getUpdate($installedVersion)
    {
        switch ($installedVersion) {
            case "1.0.0":
            case "1.0.1":
             /*
             some changes
             */
            case "1.0.2":
             /*
             Security change (SameSite-Attribut)
             */
            case "1.0.3":
             /*
             some changes
             */
            case "1.0.4":
                $this->db()->query("UPDATE `[prefix]_modules` SET `icon_small` = '" . $this->config['icon_small'] . "' WHERE `key` = '" . $this->config['key'] . "';");
                // no break
            case "1.0.5":
                /*
                some changes
                */
            case "1.0.6":
                /*
                update to ilch 2.2.0 core
                */
            case "1.0.7":
                /*
                some bugfixes BS5
                */
        }
    }

    /**
     * @return bool
     */
    private function providerExists()
    {
        return (bool) $this->db()
            ->select('key')
            ->from('auth_providers')
            ->where(['key' => 'steamauth_steam'])
            ->useFoundRows()
            ->execute()
            ->getFoundRows();
    }
}
