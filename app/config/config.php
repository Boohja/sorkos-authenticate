<?php

declare(strict_types=1);

function mergeConfig(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = mergeConfig($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

$config = [
    'app' => [
        'name' => 'Comasu Auth',
        'env' => 'local',
        'debug' => true,
        'timezone' => 'Europe/Berlin',
        'base_url' => 'http://auth.test',
    ],
    'db' => [
        'enabled' => false,
        'host' => 'localhost',
        'port' => '3306',
        'database' => '',
        'username' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'dev_client' => [
        'enabled' => true,
        'client_id' => 'local-test',
        'redirect_uri' => 'http://auth.test/dev/callback',
    ],
];

$localConfigPath = __DIR__ . '/local.php';

if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;

    if (is_array($localConfig)) {
        $config = mergeConfig($config, $localConfig);
    }
}

return $config;
