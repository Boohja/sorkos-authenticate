<?php

namespace Tests;

use Base;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE = [];
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];
        http_response_code(200);
    }

    protected function app(): Base
    {
        $f3 = Base::instance();
        $root = dirname(__DIR__);

        $f3->set('UI', $root . '/app/views/');
        $f3->set('LOCALES', $root . '/app/lang/');
        $f3->set('LANGUAGE', 'en');
        $f3->set('APP_CONFIG', [
            'app' => [
                'debug_footer' => false,
                'auth_secret' => 'test-auth-secret',
                'base_url' => 'https://auth.test',
            ],
            'db' => [
                'enabled' => false,
            ],
        ]);
        $f3->set('DB', null);
        $f3->set('layout_variant', '');
        $f3->set('client_back_url', '');
        $f3->set('client_back_label', '');
        $f3->set('client_icon', '');

        foreach (require $root . '/app/lang/en.php' as $key => $value) {
            $f3->set($key, $value);
        }

        return $f3;
    }

    protected function renderView(string $view, array $data = []): string
    {
        $f3 = $this->app();

        foreach ($data as $key => $value) {
            $f3->set($key, $value);
        }

        return \Template::instance()->render($view);
    }
}
