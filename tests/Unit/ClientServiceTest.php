<?php

use App\Services\ClientService;
use App\Services\Db;

it('parses comma separated provider lists without empty items', function (): void {
    expect(ClientService::csvToList(' email, google, ,discord ,, '))
        ->toBe(['email', 'google', 'discord']);
});

it('quotes SQL string literals by escaping single quotes', function (): void {
    expect(ClientService::quoteSqlString("Sorkos's App"))
        ->toBe("'Sorkos''s App'");
});

it('allows configured response types and rejects missing or unsupported ones', function (): void {
    $service = new ClientService(new Db([]));

    expect($service->responseTypeAllowed(['settings_json' => null], 'code'))->toBeTrue()
        ->and($service->responseTypeAllowed(['settings_json' => null], 'token'))->toBeFalse()
        ->and($service->responseTypeAllowed(['settings_json' => null], ''))->toBeFalse()
        ->and($service->responseTypeAllowed([
            'settings_json' => json_encode(['response_types' => ['code', 'device_code']]),
        ], 'device_code'))->toBeTrue()
        ->and($service->responseTypeAllowed([
            'settings_json' => json_encode(['response_types' => 'code']),
        ], 'code'))->toBeTrue();
});

it('validates confidential client secrets while allowing public clients', function (): void {
    $service = new ClientService(new Db([]));
    $hash = password_hash('correct-secret', PASSWORD_DEFAULT);

    expect($service->secretValid(['is_confidential' => 0], ''))->toBeTrue()
        ->and($service->secretValid([
            'is_confidential' => 1,
            'client_secret_hash' => $hash,
        ], 'correct-secret'))->toBeTrue()
        ->and($service->secretValid([
            'is_confidential' => 1,
            'client_secret_hash' => $hash,
        ], 'wrong-secret'))->toBeFalse()
        ->and($service->secretValid([
            'is_confidential' => 1,
            'client_secret_hash' => '',
        ], 'correct-secret'))->toBeFalse();
});
