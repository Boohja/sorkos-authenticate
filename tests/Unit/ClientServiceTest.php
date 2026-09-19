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

it('allows only the hard-coded authorization code response type', function (): void {
    $service = new ClientService(new Db([]));

    expect($service->responseTypeAllowed('code'))->toBeTrue()
        ->and($service->responseTypeAllowed('token'))->toBeFalse()
        ->and($service->responseTypeAllowed('device_code'))->toBeFalse()
        ->and($service->responseTypeAllowed(''))->toBeFalse();
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

it('returns only safe absolute account deletion URLs from client settings', function (): void {
    $service = new ClientService(new Db([]));

    expect($service->accountDeletionUrl([
        'settings_json' => json_encode(['account_deletion_url' => 'https://pulse.test/account/delete']),
    ]))->toBe('https://pulse.test/account/delete')
        ->and($service->accountDeletionUrl([
            'settings_json' => json_encode(['account_deletion_url' => 'javascript:alert(1)']),
        ]))->toBe('')
        ->and($service->accountDeletionUrl([
            'settings_json' => '{invalid',
        ]))->toBe('');
});


it('returns only safe client session revocation URLs', function (): void {
    $service = new ClientService(new Db([]));

    expect($service->sessionRevocationUrl([
        'settings_json' => json_encode(['session_revocation_url' => 'https://pulse.test/auth/sessions/revoke']),
    ]))->toBe('https://pulse.test/auth/sessions/revoke')
        ->and($service->sessionRevocationUrl([
            'settings_json' => json_encode(['session_revocation_url' => 'http://pulse.test/auth/sessions/revoke']),
        ]))->toBe('')
        ->and($service->sessionRevocationUrl([
            'settings_json' => json_encode(['session_revocation_url' => 'file:///tmp/revoke']),
        ]))->toBe('');
});
