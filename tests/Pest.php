<?php

use Tests\TestCase;

require_once dirname(__DIR__) . '/lib/f3/base.php';
require_once dirname(__DIR__) . '/app/services/Db.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $parts = explode('\\', substr($class, strlen($prefix)));

    if (isset($parts[0])) {
        $parts[0] = strtolower($parts[0]);
    }

    $path = dirname(__DIR__) . '/app/' . implode('/', $parts) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

pest()->extend(TestCase::class)->in('Feature', 'Integration');
