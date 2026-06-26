<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminSetupService;
use Base;
use PDOException;

class HomeController
{
    public function index(Base $f3): void
    {
        $config = $f3->get('APP_CONFIG');
        $db = $f3->get('DB');

        if ($db instanceof \App\Services\Db) {
            try {
                $setup = new AdminSetupService($db, dirname(__DIR__, 2));

                if ($setup->shouldRedirectToSetup()) {
                    $f3->reroute('/setup');
                    return;
                }
            } catch (\Throwable $exception) {
                // The status page below will still report the database state.
            }
        }

        $dbStatus = 'Not configured yet';

        if ($db instanceof \App\Services\Db && $db->isConfigured()) {
            try {
                $db->pdo()->query('SELECT 1');
                $dbStatus = 'Connected';
            } catch (PDOException $exception) {
                $dbStatus = 'Configured, connection failed';
            }
        }

        $f3->set('title', 'Sorkos Login');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', 'home');
        $f3->set('layout_variant', 'split');
        $f3->set('environment', (string) ($config['app']['env'] ?? 'local'));
        $f3->set('db_status', $dbStatus);
        $f3->set('base_url', (string) ($config['app']['base_url'] ?? ''));
        echo \Template::instance()->render('home.html');
    }
}
