<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminSetupService;
use App\Services\Db;
use App\Services\SessionService;
use Base;
use PDO;
use PDOException;

class HomeController
{
    public function index(Base $f3): void
    {
        $config = $f3->get('APP_CONFIG');
        $db = $f3->get('DB');

        if ($db instanceof \App\Services\Db) {
            try {
                $setup = new AdminSetupService($db, dirname(__DIR__, 2));

                if ($setup->shouldRedirectToSetup()) {
                    $f3->reroute('/setup');
                    return;
                }
            } catch (\Throwable $exception) {
                // The status page below will still report the database state.
            }
        }

        $dbStatus = 'Not configured yet';

        if ($db instanceof \App\Services\Db && $db->isConfigured()) {
            try {
                $db->pdo()->query('SELECT 1');
                $dbStatus = 'Connected';
            } catch (PDOException $exception) {
                $dbStatus = 'Configured, connection failed';
            }
        }

        $f3->set('title', 'Sorkos Login');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', 'home');
        $f3->set('layout_variant', 'split');
        $f3->set('hide_split_header', false);
        $f3->set('client_icon', '');
        $f3->set('current_user', $this->currentUser($db));
        $f3->set('current_user_label', $this->userLabel($f3->get('current_user')));
        $f3->set('environment', (string) ($config['app']['env'] ?? 'local'));
        $f3->set('db_status', $dbStatus);
        $f3->set('base_url', (string) ($config['app']['base_url'] ?? ''));
        echo \Template::instance()->render('home.html');
    }

    public function about(Base $f3): void
    {
        $this->renderStatic($f3, 'About Sorkos Login', 'about.html', 'about');
    }

    public function privacy(Base $f3): void
    {
        $this->renderStatic($f3, 'Data Privacy - Sorkos Login', 'privacy.html', 'privacy');
    }

    public function account(Base $f3): void
    {
        $db = $f3->get('DB');
        $user = $this->currentUser($db);
        $identities = $this->identityRows($db, $user);
        $clients = $this->clientRows($db, $user);

        $f3->set('title', 'Account - Sorkos Login');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', '');
        $f3->set('layout_variant', '');
        $f3->set('hide_split_header', false);
        $f3->set('client_icon', '');
        $f3->set('account_user', $user);
        $f3->set('account_user_label', $this->userLabel($user));
        $f3->set('account_user_initial', $this->initial($this->userLabel($user)));
        $f3->set('account_avatar_url', $this->safeImageUrl((string) ($user['avatar_url'] ?? '')));
        $f3->set('account_identities', $identities);
        $f3->set('account_provider_label', $this->providerSummary($identities));
        $f3->set('account_show_identity_details', $this->shouldShowIdentityDetails($identities, $user));
        $f3->set('account_can_edit_display_name', $this->hasProvider($identities, 'email'));
        $f3->set('account_display_name_updated', (string) ($f3->get('GET.updated') ?? '') === 'display-name');
        $f3->set('account_clients', $clients);
        $f3->set('account_has_clients', count($clients) > 0);

        echo \Template::instance()->render('account.html');
    }

    public function updateDisplayName(Base $f3): void
    {
        $db = $f3->get('DB');
        $user = $this->currentUser($db);
        $identities = $this->identityRows($db, $user);

        if (!$db instanceof Db || $user === null || !$this->hasProvider($identities, 'email')) {
            $f3->reroute('/account');
            return;
        }

        $displayName = trim((string) ($f3->get('POST.display_name') ?? ''));
        $displayName = substr($displayName, 0, 255);
        $currentName = trim((string) ($user['display_name'] ?? ''));

        if ($displayName !== $currentName) {
            $stmt = $db->pdo()->prepare(
                'UPDATE auth_users
                 SET display_name = :display_name,
                     updated_at = :updated_at
                 WHERE id = :id
                 AND disabled_at IS NULL'
            );
            $stmt->execute([
                'display_name' => $displayName !== '' ? $displayName : null,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => (int) $user['id'],
            ]);

            $f3->reroute('/account?updated=display-name');
            return;
        }

        $f3->reroute('/account');
    }

    private function renderStatic(Base $f3, string $title, string $template, string $activeNav): void
    {
        $f3->set('title', $title);
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', $activeNav);
        $f3->set('layout_variant', '');
        $f3->set('hide_split_header', false);
        $f3->set('client_icon', '');
        echo \Template::instance()->render($template);
    }

    private function currentUser(mixed $db): ?array
    {
        if (!$db instanceof Db) {
            return null;
        }

        return (new SessionService($db))->currentUser();
    }

    private function identityRows(mixed $db, ?array $user): array
    {
        if (!$db instanceof Db || $user === null) {
            return [];
        }

        $stmt = $db->pdo()->prepare(
            'SELECT provider, provider_email, provider_email_verified, updated_at
             FROM auth_identities
             WHERE user_id = :user_id
             ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => (int) $user['id']]);

        return array_map(function (array $identity): array {
            $provider = (string) $identity['provider'];

            return [
                'provider' => $provider,
                'provider_label' => $this->providerLabel($provider),
                'email' => (string) ($identity['provider_email'] ?? ''),
                'email_verified' => !empty($identity['provider_email_verified']),
                'updated_at' => (string) ($identity['updated_at'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function clientRows(mixed $db, ?array $user): array
    {
        if (!$db instanceof Db || $user === null) {
            return [];
        }

        $stmt = $db->pdo()->prepare(
            'SELECT c.id, c.display_name, c.domain, c.branding_json, source.connected_at, source.last_used_at
             FROM (
                SELECT client_id, MIN(connected_at) AS connected_at, MAX(last_used_at) AS last_used_at
                FROM (
                    SELECT client_id, MIN(created_at) AS connected_at, MAX(created_at) AS last_used_at
                    FROM auth_authorization_codes
                    WHERE user_id = :codes_user_id
                    GROUP BY client_id
                    UNION ALL
                    SELECT client_id, MIN(created_at) AS connected_at, MAX(updated_at) AS last_used_at
                    FROM auth_user_client_consents
                    WHERE user_id = :consents_user_id
                    AND revoked_at IS NULL
                    GROUP BY client_id
                ) client_sources
                GROUP BY client_id
             ) source
             INNER JOIN auth_clients c ON c.id = source.client_id
             ORDER BY last_used_at DESC, c.display_name ASC'
        );
        $stmt->execute([
            'codes_user_id' => (int) $user['id'],
            'consents_user_id' => (int) $user['id'],
        ]);

        return array_map(function (array $client): array {
            $logo = $this->clientBrandingUrl($client, 'logo');
            $icon = $this->clientBrandingUrl($client, 'icon');
            $name = (string) $client['display_name'];
            $connectedAt = (string) ($client['connected_at'] ?? '');

            return [
                'display_name' => $name,
                'domain' => (string) ($client['domain'] ?? ''),
                'url' => $this->clientUrl((string) ($client['domain'] ?? '')),
                'logo' => $logo !== '' ? $logo : $icon,
                'initial' => $this->initial($name),
                'connected_label' => $connectedAt !== '' ? 'Connected since ' . $this->dateLabel($connectedAt) : '',
                'last_used_at' => (string) ($client['last_used_at'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function clientBrandingUrl(array $client, string $key): string
    {
        $branding = [];

        if (!empty($client['branding_json'])) {
            $decoded = json_decode((string) $client['branding_json'], true);
            $branding = is_array($decoded) ? $decoded : [];
        }

        $value = (string) ($branding[$key] ?? $branding[$key . '_url'] ?? '');

        return $this->safeImageUrl($value);
    }

    private function userLabel(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        return (string) ($user['display_name'] ?: $user['email'] ?: $user['public_id']);
    }

    private function providerLabel(string $provider): string
    {
        return match (strtolower($provider)) {
            'email' => 'E-Mail',
            'google' => 'Google',
            'discord' => 'Discord',
            default => ucfirst($provider),
        };
    }

    private function providerSummary(array $identities): string
    {
        $labels = array_values(array_unique(array_map(
            static fn (array $identity): string => (string) ($identity['provider_label'] ?? ''),
            $identities
        )));
        $labels = array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));

        return $labels === [] ? 'Not available' : implode(', ', $labels);
    }

    private function shouldShowIdentityDetails(array $identities, ?array $user): bool
    {
        if ($user === null || count($identities) !== 1) {
            return count($identities) > 0;
        }

        $identity = $identities[0];
        $accountEmail = strtolower((string) ($user['email'] ?? ''));
        $identityEmail = strtolower((string) ($identity['email'] ?? ''));

        return $identityEmail === '' || $identityEmail !== $accountEmail;
    }

    private function hasProvider(array $identities, string $provider): bool
    {
        foreach ($identities as $identity) {
            if (strtolower((string) ($identity['provider'] ?? '')) === strtolower($provider)) {
                return true;
            }
        }

        return false;
    }

    private function safeImageUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function clientUrl(string $domain): string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return '';
        }

        $candidate = preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;

        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : '';
    }

    private function dateLabel(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('M j, Y', $timestamp);
    }

    private function initial(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '?' : strtoupper(substr($value, 0, 1));
    }
}
