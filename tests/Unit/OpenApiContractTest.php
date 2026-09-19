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
            '/client/sessions/introspect',
            '/client/connections/revoke',
            '/logout',
        ]);
});

it('documents central logout as an explicit special case rather than the product default', function (): void {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/openapi.json'), true);
    $logout = $document['paths']['/logout']['get'];

    expect($logout['summary'])->toContain('Explicitly')
        ->and($logout['description'])->toContain('A normal product logout must only destroy')
        ->and($logout['description'])->toContain('must not call or redirect to this endpoint');
});

it('documents authenticated idempotent client-connection revocation', function (): void {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/openapi.json'), true);
    $operation = $document['paths']['/client/connections/revoke']['post'];
    $request = $document['components']['schemas']['ConnectionRevocationRequest'];

    expect($operation['responses'])->toHaveKeys(['204', '400', '401'])
        ->and($request['required'])->toBe(['client_id', 'client_secret', 'user_id'])
        ->and($request['properties']['user_id']['maxLength'])->toBe(64);
});

it('keeps the token contract aligned with the implemented user and client-session payload', function (): void {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/openapi.json'), true);
    $tokenRequest = $document['components']['schemas']['TokenRequest'];
    $tokenResponse = $document['components']['schemas']['TokenResponse'];
    $user = $document['components']['schemas']['User'];

    expect($tokenRequest['required'])->toContain('grant_type', 'code', 'client_id', 'client_secret', 'redirect_uri')
        ->and($tokenRequest['properties']['grant_type']['enum'])->toBe(['authorization_code'])
        ->and($tokenResponse['required'])->toBe(['user', 'client_session_token'])
        ->and($tokenResponse['properties']['client_session_token']['pattern'])->toBe('^cst_[a-f0-9]{64}$')
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

it('documents authenticated client-session introspection', function (): void {
    $document = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/openapi.json'), true);
    $operation = $document['paths']['/client/sessions/introspect']['post'];
    $request = $document['components']['schemas']['ClientSessionIntrospectionRequest'];
    $response = $document['components']['schemas']['ClientSessionIntrospectionResponse'];

    expect($operation['responses'])->toHaveKeys(['200', '400', '401'])
        ->and($request['required'])->toBe(['client_id', 'client_secret', 'client_session_token'])
        ->and($request['properties']['client_session_token']['pattern'])->toBe('^cst_[a-f0-9]{64}$')
        ->and($response['required'])->toBe(['active'])
        ->and($response['properties']['active']['type'])->toBe('boolean');
});
