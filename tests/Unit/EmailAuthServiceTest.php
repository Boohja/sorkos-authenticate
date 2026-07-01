<?php

use App\Services\Db;
use App\Services\EmailAuthService;

it('normalizes email addresses before validation and lookup', function (): void {
    $service = new EmailAuthService(new Db([]), [
        'app' => ['auth_secret' => 'test-secret'],
    ]);

    expect($service->normalizeEmail('  USER.Name+Tag@Example.COM  '))
        ->toBe('user.name+tag@example.com');
});
