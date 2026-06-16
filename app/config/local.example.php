<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'debug' => false,
        'base_url' => 'https://auth.comasu.de',
    ],
    'db' => [
        'enabled' => true,
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'database_name',
        'username' => 'database_user',
        'password' => 'database_password',
    ],
    'dev_client' => [
        'enabled' => true,
        'client_id' => 'local-test',
        'redirect_uri' => 'http://auth.test/dev/callback',
    ],
];
