<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationCodeService;
use App\Services\ClientService;
use App\Services\SessionService;
use Base;

class ApiController
{
    public function token(Base $f3): void
    {
        $params = $this->requestParams($f3);

        if ((string) ($params['grant_type'] ?? '') !== 'authorization_code') {
            $this->json(['error' => 'unsupported_grant_type'], 400);
            return;
        }

        $clientId = trim((string) ($params['client_id'] ?? ''));
        $clientSecret = (string) ($params['client_secret'] ?? '');
        $code = trim((string) ($params['code'] ?? ''));
        $redirectUri = trim((string) ($params['redirect_uri'] ?? ''));

        if ($clientId === '' || $code === '' || $redirectUri === '') {
            $this->json(['error' => 'invalid_request'], 400);
            return;
        }

        $clients = new ClientService($f3->get('DB'));
        $client = $clients->findActiveByClientId($clientId);

        if ($client === null || !$clients->secretValid($client, $clientSecret)) {
            $this->json(['error' => 'invalid_client'], 400);
            return;
        }

        if (!$clients->redirectUriAllowed((int) $client['id'], $redirectUri)) {
            $this->json(['error' => 'invalid_grant'], 400);
            return;
        }

        $user = (new AuthorizationCodeService($f3->get('DB')))->redeem($code, (int) $client['id'], $redirectUri);

        if ($user === null) {
            $this->json([
                'error' => 'invalid_grant',
                'error_description' => 'The authorization code is invalid, expired, already used, or does not match this client.',
            ], 400);
            return;
        }

        $this->json(['user' => $user]);
    }

    public function logout(Base $f3): void
    {
        $revoked = (new SessionService($f3->get('DB')))->revokeCurrentSession();
        $returnUri = $this->validatedLogoutReturnUri($f3);

        if ($returnUri !== null) {
            header('Location: ' . $returnUri, true, 302);
            return;
        }

        $this->renderLogoutConfirmation($f3, $revoked);
    }

    private function requestParams(Base $f3): array
    {
        $headers = (array) ($f3->get('HEADERS') ?: []);
        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $f3->get('BODY'), true);

            return is_array($decoded) ? $decoded : [];
        }

        return (array) $f3->get('POST');
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function validatedLogoutReturnUri(Base $f3): ?string
    {
        $clientId = trim((string) ($f3->get('GET.client_id') ?? $f3->get('POST.client_id') ?? ''));
        $returnUri = trim((string) ($f3->get('GET.redirect_uri') ?? $f3->get('POST.redirect_uri') ?? ''));

        if ($clientId === '' || $returnUri === '') {
            return null;
        }

        $clients = new ClientService($f3->get('DB'));
        $client = $clients->findActiveByClientId($clientId);

        if ($client === null || !$clients->redirectUriAllowed((int) $client['id'], $returnUri)) {
            return null;
        }

        return $returnUri;
    }

    private function renderLogoutConfirmation(Base $f3, bool $revoked): void
    {
        $f3->set('title', 'Logged out - Sorkos Login');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', '');
        $f3->set('layout_variant', 'split');
        $f3->set('hide_split_header', true);
        $f3->set('client_icon', '');
        $f3->set('revoked', $revoked);

        echo \Template::instance()->render('logout.html');
    }
}
