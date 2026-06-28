<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'debug' => false,
        'debug_footer' => false,
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
    'tasks' => [
        'housekeeping_secret' => 'replace-with-a-second-long-random-secret',
        'email_code_retention_hours' => 24,
        'authorization_code_retention_hours' => 24,
        'session_retention_days' => 7,
    ],
];
