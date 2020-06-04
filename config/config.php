<?php
/**
 * @copyright Ilch 2
 * @package ilch
 */

namespace Modules\Steamauth\Config;

class Config extends \Ilch\Config\Install
{
    public $config = [
        'key' => 'steamauth',
        'icon_small' => 'fa-steam-square',
        'author' => 'FAOS | MonkeyOnKeyboard',
        'version' => '1.0.0',
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
        'ilchCore' => '2.1.34',
        'phpVersion' => '5.6'
    ];

    public function install()
    {
        if (! $this->providerExists()) {
            $this->db()
                ->insert('auth_providers')
                ->values([
                    'key' => 'steam',
                    'name' => 'Steam',
                    'icon' => 'fa-steam-square'
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
                'provider' => 'steam',
                'auth_controller' => 'auth',
                'auth_action' => 'index',
                'unlink_controller' => 'auth',
                'unlink_action' => 'unlink',
            ])
            ->execute();

            $databaseConfig = new \Ilch\Config\Database($this->db());
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
            ->where(['key' => 'steam'])
            ->execute();

            $this->db()->queryMulti("DELETE FROM `[prefix]_config` WHERE `key` = 'steamauth_apikey';");
    }

    public function getUpdate($installedVersion)
    {

    }

    /**
     * @return boolean
     */
    private function providerExists()
    {
        return (bool) $this->db()
            ->select('key')
            ->from('auth_providers')
            ->where(['key' => 'steam'])
            ->useFoundRows()
            ->execute()
            ->getFoundRows();
    }
}
