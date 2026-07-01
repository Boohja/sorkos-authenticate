<?php

use App\Services\AuthorizationCodeService;
use App\Services\Db;

it('builds callback URLs with code and state', function (): void {
    $service = new AuthorizationCodeService(new Db([]));

    expect($service->callbackUrl([
        'redirect_uri' => 'https://client.test/callback',
        'state' => 'client-state',
    ], 'issued-code'))->toBe('https://client.test/callback?code=issued-code&state=client-state');
});

it('appends callback parameters to redirect URLs that already contain a query string', function (): void {
    $service = new AuthorizationCodeService(new Db([]));

    expect($service->callbackUrl([
        'redirect_uri' => 'https://client.test/callback?from=login',
        'state' => 'client-state',
    ], 'issued-code'))->toBe('https://client.test/callback?from=login&code=issued-code&state=client-state');
});

it('rejects structurally invalid code redemption requests before querying storage', function (): void {
    $service = new AuthorizationCodeService(new Db([]));

    expect($service->redeem('', 1, 'https://client.test/callback'))->toBeNull()
        ->and($service->redeem('code', 0, 'https://client.test/callback'))->toBeNull()
        ->and($service->redeem('code', 1, ''))->toBeNull();
});
