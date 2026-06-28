<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationCodeService;
use App\Services\ClientService;
use App\Services\EmailAuthService;
use App\Services\I18n;
use App\Services\SessionService;
use Base;

class AuthController
{
    public function authorize(Base $f3): void
    {
        $i18n = I18n::fromRequest($f3);
        $clients = new ClientService($f3->get('DB'));
        $validation = $clients->validateAuthorizeRequest($f3->get('GET'));

        if (!$validation['ok']) {
            $this->renderError($f3, $i18n, $validation['error']);
            return;
        }

        $client = $validation['client'];
        $i18n = I18n::fromRequest($f3, $client);
        $session = new SessionService($f3->get('DB'));
        $pendingAuthorize = [
            'client_id' => $client['client_id'],
            'client_pk' => $client['id'],
            'redirect_uri' => (string) $f3->get('GET.redirect_uri'),
            'response_type' => (string) $f3->get('GET.response_type'),
            'scope' => (string) ($f3->get('GET.scope') ?? ''),
            'state' => (string) $f3->get('GET.state'),
            'lang' => $i18n->language(),
            'created_at' => time(),
        ];
        $session->storePendingAuthorizeRequest($pendingAuthorize);

        $user = $session->currentUser();

        if ($user !== null) {
            $this->redirectToClient($f3, $session, $pendingAuthorize, $user);
            return;
        }

        $this->renderLogin($f3, $i18n, $client);
    }

    public function emailForm(Base $f3): void
    {
        $context = $this->authorizeContext($f3);

        if ($context === null) {
            return;
        }

        [$i18n, $client] = $context;

        $this->render($f3, 'email_login.html', [
            'title' => $client['display_name'] . ' Login with Sorkos',
            'html_lang' => $i18n->language(),
            'active_nav' => '',
            'layout_variant' => 'split',
            'hide_split_header' => true,
            'client_icon' => '',
            'i18n' => $i18n,
            'client' => $client,
            'client_icon' => $this->clientBranding($client)['icon'],
            'email_value' => '',
            'email_error' => '',
            'back_url' => $this->authorizeBackUrl($f3),
        ]);
    }

    public function sendEmailCode(Base $f3): void
    {
        $context = $this->authorizeContext($f3);

        if ($context === null) {
            return;
        }

        [$i18n, $client, $pending] = $context;
        $emailService = new EmailAuthService($f3->get('DB'), $f3->get('APP_CONFIG'));
        $email = $emailService->normalizeEmail((string) $f3->get('POST.email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->render($f3, 'email_login.html', [
                'title' => $client['display_name'] . ' Login with Sorkos',
                'html_lang' => $i18n->language(),
                'active_nav' => '',
                'layout_variant' => 'split',
                'hide_split_header' => true,
                'i18n' => $i18n,
                'client' => $client,
                'client_icon' => $this->clientBranding($client)['icon'],
                'email_value' => $email,
                'email_error' => $i18n->t('email.invalid'),
                'back_url' => $this->authorizeBackUrl($f3),
            ]);
            return;
        }

        $requestStatus = $emailService->codeRequestStatus($email, (int) $pending['client_pk']);

        if (!$requestStatus['ok']) {
            $this->render($f3, 'email_login.html', [
                'title' => $client['display_name'] . ' Login with Sorkos',
                'html_lang' => $i18n->language(),
                'active_nav' => '',
                'layout_variant' => 'split',
                'hide_split_header' => true,
                'i18n' => $i18n,
                'client' => $client,
                'client_icon' => $this->clientBranding($client)['icon'],
                'email_value' => $email,
                'email_error' => $i18n->t((string) $requestStatus['error']),
                'back_url' => $this->authorizeBackUrl($f3),
            ]);
            return;
        }

        $challenge = $emailService->createCode($email, (int) $pending['client_pk']);
        $_SESSION['email_login_challenge'] = [
            'id' => $challenge['id'],
            'selector' => $challenge['selector'],
            'email' => $email,
            'client_pk' => (int) $pending['client_pk'],
        ];

        $mailSent = $emailService->sendCode($email, $challenge['code'], $this->absoluteUrl($f3, '/oauth/email/verify'), $i18n);
        $_SESSION['email_login_mail_sent'] = $mailSent;

        $f3->reroute('/oauth/email/verify');
    }

    public function emailCodeForm(Base $f3): void
    {
        $context = $this->authorizeContext($f3);

        if ($context === null) {
            return;
        }

        [$i18n, $client] = $context;
        $challenge = $_SESSION['email_login_challenge'] ?? null;

        if (!is_array($challenge)) {
            $f3->reroute('/oauth/email');
            return;
        }

        $this->render($f3, 'email_code.html', [
            'title' => $client['display_name'] . ' Login with Sorkos',
            'html_lang' => $i18n->language(),
            'active_nav' => '',
            'layout_variant' => 'split',
            'hide_split_header' => true,
            'i18n' => $i18n,
            'client' => $client,
            'client_icon' => $this->clientBranding($client)['icon'],
            'code_sent_message' => $i18n->t('email.code_sent', ['email' => (string) $challenge['email']]),
            'code_error' => '',
            'prefill_code' => $this->sanitizeCode((string) ($f3->get('GET.code') ?? '')),
            'mail_sent' => (bool) ($_SESSION['email_login_mail_sent'] ?? true),
            'back_url' => '/oauth/email',
        ]);
    }

    public function verifyEmailCode(Base $f3): void
    {
        $context = $this->authorizeContext($f3);

        if ($context === null) {
            return;
        }

        [$i18n, $client, $pending] = $context;
        $challenge = $_SESSION['email_login_challenge'] ?? null;

        if (!is_array($challenge)) {
            $f3->reroute('/oauth/email');
            return;
        }

        $code = $this->postedCode($f3);
        $emailService = new EmailAuthService($f3->get('DB'), $f3->get('APP_CONFIG'));
        $verified = $emailService->verifyCode((int) $challenge['id'], (string) $challenge['selector'], $code);

        if ($verified === null) {
            $this->render($f3, 'email_code.html', [
                'title' => $client['display_name'] . ' Login with Sorkos',
                'html_lang' => $i18n->language(),
                'active_nav' => '',
                'layout_variant' => 'split',
                'hide_split_header' => true,
                'i18n' => $i18n,
                'client' => $client,
                'client_icon' => $this->clientBranding($client)['icon'],
                'code_sent_message' => $i18n->t('email.code_sent', ['email' => (string) $challenge['email']]),
                'code_error' => $i18n->t('email.code_invalid'),
                'prefill_code' => '',
                'mail_sent' => true,
                'back_url' => '/oauth/email',
            ]);
            return;
        }

        unset($_SESSION['email_login_challenge']);
        unset($_SESSION['email_login_mail_sent']);

        $user = $emailService->createOrUpdateUser((string) $verified['email'], $i18n->language());
        $session = new SessionService($f3->get('DB'));
        $session->createSession($user);
        $this->redirectToClient($f3, $session, $pending, $user);
    }

    private function renderLogin(Base $f3, I18n $i18n, array $client): void
    {
        $branding = $this->clientBranding($client);

        $this->render($f3, 'login.html', [
            'title' => $client['display_name'] . ' Login with Sorkos',
            'html_lang' => $i18n->language(),
            'active_nav' => '',
            'layout_variant' => 'split',
            'hide_split_header' => true,
            'i18n' => $i18n,
            'client' => $client,
            'login_explanation' => $i18n->t('login.explanation', ['client' => $client['display_name']]),
            'login_prompt' => $i18n->t('login.prompt'),
            'privacy_hint' => $i18n->t('login.privacy_hint'),
            'providers' => $this->providerChoices($i18n, ClientService::csvToList((string) $client['enabled_providers'])),
            'client_logo' => $branding['logo'],
            'client_icon' => $branding['icon'],
        ]);
    }

    private function renderConsentPending(Base $f3, I18n $i18n, array $client, array $user): void
    {
        $this->render($f3, 'consent_pending.html', [
            'title' => $i18n->t('consent.title', ['client' => $client['display_name']]),
            'html_lang' => $i18n->language(),
            'active_nav' => '',
            'layout_variant' => 'split',
            'hide_split_header' => true,
            'i18n' => $i18n,
            'client' => $client,
            'client_icon' => $this->clientBranding($client)['icon'],
            'user' => $user,
            'consent_title' => $i18n->t('consent.title', ['client' => $client['display_name']]),
        ]);
    }

    private function redirectToClient(Base $f3, SessionService $session, array $pending, array $user): void
    {
        $authorizationCodes = new AuthorizationCodeService($f3->get('DB'));
        $code = $authorizationCodes->issue($pending, $user);
        $redirectUrl = $authorizationCodes->callbackUrl($pending, $code);
        $session->clearPendingAuthorizeRequest();
        $this->externalRedirect($redirectUrl);
    }

    private function renderError(Base $f3, I18n $i18n, string $error): void
    {
        http_response_code(400);

        $this->render($f3, 'error.html', [
            'title' => $i18n->t('error.title'),
            'html_lang' => $i18n->language(),
            'active_nav' => '',
            'layout_variant' => 'split',
            'hide_split_header' => true,
            'client_icon' => '',
            'i18n' => $i18n,
            'error_key' => $error,
            'error_message' => $i18n->t($error),
        ]);
    }

    private function render(Base $f3, string $view, array $data): void
    {
        foreach ($data as $key => $value) {
            $f3->set($key, $value);
        }

        echo \Template::instance()->render($view);
    }

    private function providerChoices(I18n $i18n, array $providers): array
    {
        return array_map(function (string $provider) use ($i18n): array {
            $name = strtolower($provider);
            $key = 'login.with_' . $name;
            $label = $i18n->t($key);

            return [
                'name' => $name,
                'label' => $label === $key ? ucfirst($name) : $label,
                'href' => $name === 'email' ? '/oauth/email' : '',
                'enabled' => $name === 'email',
                'icon' => $this->providerIcon($name),
            ];
        }, $providers);
    }

    private function authorizeBackUrl(Base $f3): string
    {
        return (new SessionService($f3->get('DB')))->pendingAuthorizeUrl();
    }

    private function externalRedirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function providerIcon(string $provider): string
    {
        if ($provider === 'google') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.48 10.92v3.28h7.84c-.24 1.84-.85 3.19-1.79 4.13-1.15 1.15-2.93 2.4-6.05 2.4-4.83 0-8.6-3.89-8.6-8.72s3.77-8.72 8.6-8.72c2.6 0 4.51 1.03 5.91 2.35l2.31-2.31C18.75 1.44 16.13 0 12.48 0 5.87 0 .31 5.39.31 12s5.56 12 12.17 12c3.57 0 6.27-1.17 8.37-3.36 2.16-2.16 2.84-5.21 2.84-7.67 0-.76-.05-1.47-.17-2.05H12.48z"/></svg>';
        }

        if ($provider === 'discord') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M20.32 4.37A19.8 19.8 0 0 0 15.36 2c-.21.38-.46.9-.63 1.3a18.38 18.38 0 0 0-5.46 0A13.1 13.1 0 0 0 8.64 2a19.74 19.74 0 0 0-4.96 2.38C.54 9.09-.32 13.68.1 18.2a19.92 19.92 0 0 0 6.08 3.08c.49-.67.93-1.38 1.3-2.12-.72-.27-1.41-.6-2.07-.99.17-.13.34-.26.5-.4a14.18 14.18 0 0 0 12.18 0c.17.14.33.27.5.4-.66.39-1.35.72-2.07.99.37.74.8 1.45 1.3 2.12a19.88 19.88 0 0 0 6.08-3.08c.5-5.24-.84-9.79-3.58-13.83ZM8.02 15.41c-1.18 0-2.16-1.08-2.16-2.42s.95-2.43 2.16-2.43c1.21 0 2.18 1.1 2.16 2.43 0 1.34-.95 2.42-2.16 2.42Zm7.96 0c-1.18 0-2.16-1.08-2.16-2.42s.95-2.43 2.16-2.43c1.21 0 2.18 1.1 2.16 2.43 0 1.34-.95 2.42-2.16 2.42Z"/></svg>';
        }

        if ($provider === 'email') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.75 5.5h16.5A1.75 1.75 0 0 1 22 7.25v9.5a1.75 1.75 0 0 1-1.75 1.75H3.75A1.75 1.75 0 0 1 2 16.75v-9.5A1.75 1.75 0 0 1 3.75 5.5Zm.75 2.34v8.66h15V7.84l-7.06 5.19a.75.75 0 0 1-.88 0L4.5 7.84Zm13.42-.84H6.08L12 11.35 17.92 7Z"/></svg>';
        }

        return '';
    }

    private function authorizeContext(Base $f3): ?array
    {
        $session = new SessionService($f3->get('DB'));
        $pending = $session->pendingAuthorizeRequest();
        $i18n = I18n::fromRequest($f3);

        if ($pending === null) {
            $error = $session->pendingAuthorizeExpired()
                ? 'error.expired_authorize_request'
                : 'error.missing_authorize_request';
            $this->renderError($f3, $i18n, $error);
            return null;
        }

        $client = (new ClientService($f3->get('DB')))->findActiveByClientId((string) $pending['client_id']);

        if ($client === null) {
            $this->renderError($f3, $i18n, 'error.invalid_client');
            return null;
        }

        $i18n = I18n::fromRequest($f3, $client);
        $providers = ClientService::csvToList((string) $client['enabled_providers']);
        $providers = array_map('strtolower', $providers);

        if (!in_array('email', $providers, true)) {
            $this->renderError($f3, $i18n, 'error.provider_unavailable');
            return null;
        }

        return [$i18n, $client, $pending];
    }

    private function postedCode(Base $f3): string
    {
        $parts = (array) ($f3->get('POST.email_code_digit') ?? $f3->get('POST.code') ?? []);
        $code = implode('', array_map(static fn ($part): string => preg_replace('/\D+/', '', (string) $part), $parts));

        if ($code === '') {
            $code = preg_replace('/\D+/', '', (string) ($f3->get('POST.email_code_full') ?? $f3->get('POST.code_full')));
        }

        return substr($code, 0, 6);
    }

    private function sanitizeCode(string $code): string
    {
        return substr(preg_replace('/\D+/', '', $code), 0, 6);
    }

    private function absoluteUrl(Base $f3, string $path): string
    {
        $headers = $f3->get('HEADERS') ?: [];
        $server = $f3->get('SERVER') ?: [];
        $scheme = strtolower((string) ($headers['X-Forwarded-Proto'] ?? ''));

        if ($scheme === '') {
            $scheme = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off'
                ? 'https'
                : (string) ($f3->get('SCHEME') ?: 'http');
        }

        $host = (string) ($headers['X-Forwarded-Host'] ?? $headers['Host'] ?? $f3->get('HOST'));
        $host = trim(explode(',', $host)[0]);

        if ($host === '') {
            $config = $f3->get('APP_CONFIG');
            $baseUrl = rtrim((string) ($config['app']['base_url'] ?? ''), '/');

            return $baseUrl . $path;
        }

        return rtrim($scheme . '://' . $host, '/') . $path;
    }

    private function clientBranding(array $client): array
    {
        $branding = [];

        if (!empty($client['branding_json'])) {
            $decoded = json_decode((string) $client['branding_json'], true);
            $branding = is_array($decoded) ? $decoded : [];
        }

        return [
            'icon' => (string) ($branding['icon'] ?? $branding['icon_url'] ?? ''),
            'logo' => (string) ($branding['logo'] ?? $branding['logo_url'] ?? ''),
        ];
    }
}
