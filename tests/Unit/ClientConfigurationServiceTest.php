<?php

use App\Services\ClientConfigurationService;

it('lists only supported configuration keys that have not been used', function (): void {
    $groups = (new ClientConfigurationService())->groups([
        'branding_json' => json_encode(['icon' => '/img/pulse.svg']),
        'settings_json' => json_encode(['response_types' => ['code']]),
    ]);

    expect(array_column($groups[0]['entries'], 'key'))->toBe(['icon'])
        ->and(array_column($groups[0]['available'], 'key'))->toBe(['logo'])
        ->and(array_column($groups[1]['entries'], 'key'))->toBe([])
        ->and(array_column($groups[1]['available'], 'key'))->toBe(['account_deletion_url', 'session_revocation_url', 'user_deletion_url']);
});

it('normalizes supported values and rejects unsafe URLs or removed keys', function (): void {
    $service = new ClientConfigurationService();
    $settings = $service->add('{}', 'settings', 'account_deletion_url', 'https://pulse.test/account/delete');

    expect(json_decode((string) $settings, true))->toBe([
        'account_deletion_url' => 'https://pulse.test/account/delete',
    ])->and($service->add('{}', 'settings', 'response_types', 'code'))->toBeNull()
        ->and($service->add((string) $settings, 'settings', 'account_deletion_url', 'https://other.test/delete'))->toBeNull()
        ->and($service->add('{}', 'settings', 'account_deletion_url', 'javascript:alert(1)'))->toBeNull()
        ->and($service->add('{}', 'branding', 'icon', '/img/pulse.svg'))->not->toBeNull();
});

it('removes one entry while preserving other and unknown configuration', function (): void {
    $service = new ClientConfigurationService();
    $updated = $service->remove(
        json_encode(['account_deletion_url' => 'https://pulse.test/delete', 'custom' => true]),
        'settings',
        'account_deletion_url'
    );

    expect(json_decode((string) $updated, true))->toBe(['custom' => true]);
});