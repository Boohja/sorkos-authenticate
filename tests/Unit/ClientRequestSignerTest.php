<?php

use App\Services\ClientRequestSigner;

it('derives and verifies the documented v1 client request signature', function (): void {
    $secret = 'correct horse battery staple';
    $client = [
        'client_id' => 'local-pulse',
        'is_confidential' => 1,
        'request_signing_key' => ClientRequestSigner::deriveKey($secret),
    ];
    $body = '{"event":"logout_all","user_id":"usr_example"}';
    $timestamp = 1789570000;
    $nonce = '48f8c4e9d7024df4a251851f214bdc88';
    $canonical = implode("\n", [
        'v1',
        'POST',
        '/auth/sorkos/sessions/revoke?source=account',
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);
    $expected = 'v1=5c748e4c35f4dabfa59fd6ed7dffd9e6b4c06f65f8720f27d4a758fae5e4e812';

    expect(ClientRequestSigner::canSign($client))->toBeTrue()
        ->and(ClientRequestSigner::requestTarget('https://pulse.test/auth/sorkos/sessions/revoke?source=account'))
        ->toBe('/auth/sorkos/sessions/revoke?source=account')
        ->and(ClientRequestSigner::signature(
            $client,
            'POST',
            'https://pulse.test/auth/sorkos/sessions/revoke?source=account',
            $body,
            $timestamp,
            $nonce
        ))->toBe($expected);
});

it('refuses to sign when the derived client key is unavailable or malformed', function (): void {
    expect(ClientRequestSigner::canSign(['client_id' => 'pulse', 'is_confidential' => 1]))->toBeFalse()
        ->and(ClientRequestSigner::canSign([
            'client_id' => 'pulse',
            'is_confidential' => 1,
            'request_signing_key' => 'not-a-key',
        ]))->toBeFalse()
        ->and(ClientRequestSigner::canSign([
            'client_id' => 'pulse',
            'is_confidential' => 0,
            'request_signing_key' => str_repeat('a', 64),
        ]))->toBeFalse()
        ->and(ClientRequestSigner::signature(
            ['client_id' => 'pulse', 'is_confidential' => 1],
            'POST',
            'https://pulse.test/revoke',
            '{}',
            1789570000,
            'nonce'
        ))->toBe('');
});
it('sends signed JSON only over HTTPS and accepts only successful responses', function (): void {
    $captured = [];
    $client = [
        'client_id' => 'local-pulse',
        'is_confidential' => 1,
        'request_signing_key' => ClientRequestSigner::deriveKey('secret'),
    ];
    $signer = new ClientRequestSigner(function (string $url, string $body, array $headers) use (&$captured): int {
        $captured = compact('url', 'body', 'headers');
        return 204;
    });

    expect($signer->postJson($client, 'http://pulse.test/revoke', ['user_id' => 'usr_example']))->toBeFalse()
        ->and($captured)->toBe([])
        ->and($signer->postJson($client, 'https://pulse.test/revoke', ['user_id' => 'usr_example']))->toBeTrue()
        ->and($captured['url'])->toBe('https://pulse.test/revoke')
        ->and($captured['body'])->toBe('{"user_id":"usr_example"}')
        ->and(implode("
", $captured['headers']))->toContain('X-Sorkos-Client-Id: local-pulse')
        ->and(implode("
", $captured['headers']))->toContain('X-Sorkos-Signature: v1=');

    $failingSigner = new ClientRequestSigner(static fn (): int => 500);
    expect($failingSigner->postJson($client, 'https://pulse.test/revoke', ['user_id' => 'usr_example']))
        ->toBeFalse();
});
it('parses production HTTP status lines and fails closed without one', function (): void {
    expect(ClientRequestSigner::responseStatus(['HTTP/1.1 204 No Content']))->toBe(204)
        ->and(ClientRequestSigner::responseStatus(['HTTP/2 302']))->toBe(302)
        ->and(ClientRequestSigner::responseStatus([]))->toBe(0);
});
