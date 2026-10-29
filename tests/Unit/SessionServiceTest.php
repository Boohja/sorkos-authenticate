<?php

use App\Services\Db;
use App\Services\SessionService;

it('derives compact session descriptions without storing raw user agent details', function (): void {
    expect(SessionService::describeUserAgent('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'))
        ->toBe('Chrome on Windows')
        ->and(SessionService::describeUserAgent('Mozilla/5.0 (iPhone) AppleWebKit/605.1 Version/17.0 Mobile/15E148 Safari/604.1'))
        ->toBe('Safari on iPhone')
        ->and(SessionService::describeUserAgent(''))->toBe('Unknown browser');
});

it('uses a 30 day default and accepts minus one as unlimited session lifetime', function (): void {
    $db = new Db(['enabled' => false]);
    $property = new ReflectionProperty(SessionService::class, 'sessionTtlDays');

    expect($property->getValue(new SessionService($db)))->toBe(30)
        ->and($property->getValue(new SessionService($db, [
            'app' => ['session_ttl_days' => -1],
        ])))->toBe(-1)
        ->and($property->getValue(new SessionService($db, [
            'app' => ['session_ttl_days' => 0],
        ])))->toBe(30);
});
