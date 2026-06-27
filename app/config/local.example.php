<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'debug' => false,
        'base_url' => 'https://auth.sorkos.net',
        'auth_secret' => 'replace-with-a-long-random-secret',
    ],
    'db' => [
        'enabled' => true,
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'database_name',
        'username' => 'database_user',
        'password' => 'database_password',
    ],
];
