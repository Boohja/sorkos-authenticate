<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ClientService;
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
        $session->storePendingAuthorizeRequest([
            'client_id' => $client['client_id'],
            'client_pk' => $client['id'],
            'redirect_uri' => (string) $f3->get('GET.redirect_uri'),
            'response_type' => (string) $f3->get('GET.response_type'),
            'scope' => (string) ($f3->get('GET.scope') ?? ''),
            'state' => (string) $f3->get('GET.state'),
            'lang' => $i18n->language(),
            'created_at' => time(),
        ]);

        $user = $session->currentUser();

        if ($user !== null) {
            $this->renderConsentPending($f3, $i18n, $client, $user);
            return;
        }

        $this->renderLogin($f3, $i18n, $client);
    }

    private function renderLogin(Base $f3, I18n $i18n, array $client): void
    {
        $this->render($f3, 'login.html', [
            'title' => $i18n->t('login.title'),
            'html_lang' => $i18n->language(),
            'i18n' => $i18n,
            'client' => $client,
            'login_message' => $i18n->t('login.continue_to', ['client' => $client['display_name']]),
            'providers' => $this->providerChoices($i18n, ClientService::csvToList((string) $client['enabled_providers'])),
        ]);
    }

    private function renderConsentPending(Base $f3, I18n $i18n, array $client, array $user): void
    {
        $this->render($f3, 'consent_pending.html', [
            'title' => $i18n->t('consent.title', ['client' => $client['display_name']]),
            'html_lang' => $i18n->language(),
            'i18n' => $i18n,
            'client' => $client,
            'user' => $user,
            'consent_title' => $i18n->t('consent.title', ['client' => $client['display_name']]),
        ]);
    }

    private function renderError(Base $f3, I18n $i18n, string $error): void
    {
        http_response_code(400);

        $this->render($f3, 'error.html', [
            'title' => $i18n->t('error.title'),
            'html_lang' => $i18n->language(),
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
        return array_map(static function (string $provider) use ($i18n): array {
            $key = 'login.with_' . strtolower($provider);
            $label = $i18n->t($key);

            return [
                'name' => $provider,
                'label' => $label === $key ? ucfirst($provider) : $label,
            ];
        }, $providers);
    }
}
