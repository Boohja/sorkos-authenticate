<?php

use App\Services\SessionService;

it('derives compact session descriptions without storing raw user agent details', function (): void {
    expect(SessionService::describeUserAgent('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'))
        ->toBe('Chrome on Windows')
        ->and(SessionService::describeUserAgent('Mozilla/5.0 (iPhone) AppleWebKit/605.1 Version/17.0 Mobile/15E148 Safari/604.1'))
        ->toBe('Safari on iPhone')
        ->and(SessionService::describeUserAgent(''))->toBe('Unknown browser');
});
