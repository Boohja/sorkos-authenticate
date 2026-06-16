<?php

declare(strict_types=1);

namespace App\Controllers;

use Base;
use PDOException;

class HomeController
{
    public function index(Base $f3): void
    {
        $config = $f3->get('APP_CONFIG');
        $db = $f3->get('DB');
        $dbStatus = 'Not configured yet';

        if ($db instanceof \App\Services\Db && $db->isConfigured()) {
            try {
                $db->pdo()->query('SELECT 1');
                $dbStatus = 'Connected';
            } catch (PDOException $exception) {
                $dbStatus = 'Configured, connection failed';
            }
        }

        $f3->set('title', 'Comasu Auth');
        $f3->set('html_lang', 'en');
        $f3->set('environment', (string) ($config['app']['env'] ?? 'local'));
        $f3->set('db_status', $dbStatus);
        echo \Template::instance()->render('home.html');
    }
}
