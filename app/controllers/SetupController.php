<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminSetupService;
use Base;
use RuntimeException;

class SetupController
{
    public function show(Base $f3): void
    {
        $setup = $this->setupService($f3);

        try {
            $credentials = $setup->createPendingAdmin();
        } catch (RuntimeException $exception) {
            $f3->error(404);
            return;
        }

        $this->render($f3, $setup, $credentials, null, false);
    }

    public function verify(Base $f3): void
    {
        $setup = $this->setupService($f3);
        $pending = $setup->pendingAdminForSession();

        if ($pending === null) {
            $f3->error(404);
            return;
        }

        $code = (string) $f3->get('POST.totp_code');

        if ($setup->activatePendingAdmin($code)) {
            $f3->set('title', 'Admin Setup Complete');
            $f3->set('html_lang', 'en');
            $f3->set('active_nav', '');
            echo \Template::instance()->render('setup_complete.html');
            return;
        }

        $setup->forgetPendingSession();
        $f3->set('title', 'Admin Setup Failed');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', '');
        echo \Template::instance()->render('setup_failed.html');
    }

    private function render(Base $f3, AdminSetupService $setup, array $credentials, ?string $error, bool $isRetry): void
    {
        $f3->set('title', 'Admin Setup');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', '');
        $f3->set('setup_username', $credentials['username']);
        $f3->set('setup_password', $credentials['password']);
        $f3->set('setup_totp_secret', $credentials['totp_secret']);
        $f3->set('setup_error', $error);
        $f3->set('setup_is_retry', $isRetry);
        echo \Template::instance()->render('setup.html');
    }

    private function setupService(Base $f3): AdminSetupService
    {
        return new AdminSetupService($f3->get('DB'), dirname(__DIR__, 2));
    }
}
