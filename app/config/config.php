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
        'name' => 'Sorkos Login',
        'env' => 'local',
        'debug' => true,
        'timezone' => 'Europe/Berlin',
        'base_url' => 'http://auth.test',
        'auth_secret' => '',
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
    'tasks' => [
        'housekeeping_secret' => '',
        'email_code_retention_hours' => 24,
        'authorization_code_retention_hours' => 24,
        'session_retention_days' => 7,
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
