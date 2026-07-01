<?php

declare(strict_types=1);

require __DIR__ . '/lib/f3/base.php';
require __DIR__ . '/app/services/Db.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $parts = explode('\\', substr($class, strlen($prefix)));

    if (isset($parts[0])) {
        $parts[0] = strtolower($parts[0]);
    }

    $path = __DIR__ . '/app/' . implode('/', $parts) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$f3 = Base::instance();
$config = require __DIR__ . '/app/config/config.php';

$f3->set('TEMP', __DIR__ . '/tmp/');
$f3->set('AUTOLOAD', __DIR__ . '/app/controllers/|' . __DIR__ . '/app/models/|' . __DIR__ . '/app/services/');
$f3->set('DEBUG', (int) ($config['app']['debug'] ?? 0));
$f3->set('APP_CONFIG', $config);
$f3->set('UI', __DIR__ . '/app/views/');
$f3->set('LOCALES', __DIR__ . '/app/lang/');
$f3->set('LANGUAGE', 'en');
$f3->set('REROUTE_TRAILING_SLASH', false);
$f3->set('layout_variant', '');
$f3->set('client_back_url', '');
$f3->set('client_back_label', '');

if (!empty($config['app']['timezone'])) {
    date_default_timezone_set($config['app']['timezone']);
}

$f3->set('DB', new App\Services\Db($config['db'] ?? []));

require __DIR__ . '/app/routes.php';

$f3->run();
