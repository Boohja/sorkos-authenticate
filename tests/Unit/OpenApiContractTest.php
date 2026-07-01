<?php

it('documents the implemented OAuth and session endpoints', function (): void {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/openapi.json'), true);

    expect($document)->toBeArray()
        ->and($document['openapi'])->toStartWith('3.')
        ->and($document['paths'])->toHaveKeys([
            '/authorize',
            '/oauth/email',
            '/oauth/email/verify',
            '/token',
            '/logout',
        ]);
});

it('keeps the token contract aligned with the implemented normalized user payload', function (): void {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/openapi.json'), true);
    $tokenRequest = $document['components']['schemas']['TokenRequest'];
    $user = $document['components']['schemas']['User'];

    expect($tokenRequest['required'])->toContain('grant_type', 'code', 'client_id', 'client_secret', 'redirect_uri')
        ->and($tokenRequest['properties']['grant_type']['enum'])->toBe(['authorization_code'])
        ->and($user['required'])->toContain('id', 'email_verified')
        ->and($user['properties'])->toHaveKeys([
            'id',
            'email',
            'email_verified',
            'display_name',
            'avatar_url',
            'preferred_language',
        ]);
});
