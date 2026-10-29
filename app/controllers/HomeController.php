<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminSetupService;
use App\Services\ClientService;
use App\Services\ClientRequestSigner;
use App\Services\Db;
use App\Services\I18n;
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
        $f3->set('current_user', $this->currentUser($db, (array) $f3->get('APP_CONFIG')));
        $f3->set('current_user_label', $this->userLabel($f3->get('current_user')));
        $f3->set('environment', (string) ($config['app']['env'] ?? 'local'));
        $f3->set('db_status', $dbStatus);
        $f3->set('base_url', (string) ($config['app']['base_url'] ?? ''));
        echo \Template::instance()->render('home.html');
    }

    public function about(Base $f3): void
    {
        $this->renderStatic($f3, 'about.page_title', 'about.html', 'about');
    }

    public function privacy(Base $f3): void
    {
        $this->renderStatic($f3, 'privacy.page_title', 'privacy.html', 'privacy');
    }

    public function account(Base $f3): void
    {
        $db = $f3->get('DB');
        $user = $this->currentUser($db, (array) $f3->get('APP_CONFIG'));
        $returnTo = $this->loginReturnUrl($f3);
        $i18n = $this->accountI18n($f3, $user, $returnTo);
        $identities = $this->identityRows($db, $user);
        $clients = $this->clientRows($db, $user, $i18n);

        $f3->set('title', $i18n->t('account.page_title'));
        $f3->set('html_lang', $i18n->language());
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
        $f3->set('account_return_url', $returnTo !== '' ? $returnTo : '/');
        $f3->set('account_return_label', $i18n->t($returnTo !== '' ? 'action.back_to_login' : 'account.back_home'));
        $f3->set('account_display_name_action', $this->accountUrl($returnTo, true));

        echo \Template::instance()->render('account.html');
    }

    public function clientDetail(Base $f3, array $params): void
    {
        $db = $f3->get('DB');
        $user = $this->currentUser($db, (array) $f3->get('APP_CONFIG'));

        if (!$db instanceof Db || $user === null) {
            $f3->reroute('/account');
            return;
        }

        $client = $this->clientForUser($db, (int) $user['id'], (int) ($params['id'] ?? 0));

        if ($client === null) {
            $f3->reroute('/account');
            return;
        }

        $i18n = $this->accountI18n($f3, $user);
        $clientService = new ClientService($db);
        $sessionService = new SessionService($db, (array) $f3->get('APP_CONFIG'));
        $sessions = array_map(function (array $session) use ($i18n): array {
            $description = trim((string) ($session['session_description'] ?? ''));
            $session['description'] = $description !== '' ? $description : $i18n->t('account.session_unknown');
            $session['created_label'] = $i18n->t('account.session_started', [
                $this->dateTimeLabel((string) $session['created_at'], $i18n->language()),
            ]);
            $session['last_used_label'] = $i18n->t('account.session_last_used', [
                $this->dateTimeLabel((string) $session['last_used_at'], $i18n->language()),
            ]);
            return $session;
        }, $sessionService->sessionsForClient((int) $user['id'], (int) $client['id']));
        $name = (string) $client['display_name'];
        $connectedAt = (string) ($client['connected_at'] ?? '');
        $lastUsedAt = (string) ($client['last_used_at'] ?? '');
        $status = trim((string) ($f3->get('GET.sessions') ?? ''));

        $f3->set('title', $name . ' - ' . $i18n->t('account.page_title'));
        $f3->set('html_lang', $i18n->language());
        $f3->set('active_nav', '');
        $f3->set('layout_variant', '');
        $f3->set('hide_split_header', false);
        $f3->set('client_icon', '');
        $f3->set('account_client', [
            'id' => (int) $client['id'],
            'display_name' => $name,
            'domain' => (string) ($client['domain'] ?? ''),
            'icon' => $this->clientBrandingUrl($client, 'icon'),
            'initial' => $this->initial($name),
            'connected_label' => $connectedAt !== ''
                ? $i18n->t('account.connected_since', [$this->dateLabel($connectedAt, $i18n->language())])
                : '',
            'last_used_label' => $lastUsedAt !== ''
                ? $i18n->t('account.last_used', [$this->dateLabel($lastUsedAt, $i18n->language())])
                : '',
            'shared_data' => $this->sharedDataLabels((string) ($client['scope'] ?? ''), $i18n),
            'deletion_url' => $clientService->accountDeletionUrl($client),
            'deletion_label' => $i18n->t('account.open_deletion', [$name]),
            'has_remote_logout' => $clientService->sessionRevocationUrl($client) !== ''
                && ClientRequestSigner::canSign($client),
        ]);
        $f3->set('account_client_sessions', $sessions);
        $f3->set('account_client_status_key', match ($status) {
            'one' => 'account.session_invalidated',
            'all' => 'account.sessions_invalidated',
            'remote' => 'account.remote_logout_complete',
            'remote-failed' => 'account.remote_logout_failed',
            default => '',
        });

        echo \Template::instance()->render('account_client.html');
    }

    public function revokeClientSession(Base $f3, array $params): void
    {
        $context = $this->clientActionContext($f3, $params);

        if ($context === null) {
            return;
        }

        [$user, $client, $sessions] = $context;
        $sessions->revokeClientSession(
            (int) $user['id'],
            (int) $client['id'],
            (int) ($params['session_id'] ?? 0)
        );
        $f3->reroute('/account/clients/' . (int) $client['id'] . '?sessions=one');
    }

    public function revokeAllClientSessions(Base $f3, array $params): void
    {
        $context = $this->clientActionContext($f3, $params);

        if ($context === null) {
            return;
        }

        [$user, $client, $sessions] = $context;
        $sessions->revokeAllClientSessions((int) $user['id'], (int) $client['id']);
        $f3->reroute('/account/clients/' . (int) $client['id'] . '?sessions=all');
    }

    public function logoutClientEverywhere(Base $f3, array $params): void
    {
        $context = $this->clientActionContext($f3, $params);

        if ($context === null) {
            return;
        }

        [$user, $client, $sessions] = $context;
        $clientService = new ClientService($f3->get('DB'));
        $endpoint = $clientService->sessionRevocationUrl($client);

        if ($endpoint === '') {
            $f3->reroute('/account/clients/' . (int) $client['id']);
            return;
        }

        $sessions->revokeAllClientSessions((int) $user['id'], (int) $client['id']);
        $remoteRevoked = (new ClientRequestSigner())->postJson($client, $endpoint, [
            'event' => 'logout_all',
            'user_id' => (string) $user['public_id'],
        ]);
        $f3->reroute('/account/clients/' . (int) $client['id'] . '?sessions=' . ($remoteRevoked ? 'remote' : 'remote-failed'));
    }

    public function updateDisplayName(Base $f3): void
    {
        $db = $f3->get('DB');
        $user = $this->currentUser($db, (array) $f3->get('APP_CONFIG'));
        $identities = $this->identityRows($db, $user);
        $returnTo = $this->loginReturnUrl($f3);

        if (!$db instanceof Db || $user === null || !$this->hasProvider($identities, 'email')) {
            $f3->reroute($this->accountUrl($returnTo));
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

            $f3->reroute($this->accountUrl($returnTo, false, 'display-name'));
            return;
        }

        $f3->reroute($this->accountUrl($returnTo));
    }

    private function renderStatic(Base $f3, string $titleKey, string $template, string $activeNav): void
    {
        $returnTo = $this->loginReturnUrl($f3);
        $i18n = $this->staticPageI18n($f3, $returnTo);
        $f3->set('title', $i18n->t($titleKey));
        $f3->set('html_lang', $i18n->language());
        $f3->set('active_nav', $activeNav);
        $f3->set('layout_variant', '');
        $f3->set('hide_split_header', false);
        $f3->set('client_icon', '');
        $f3->set('return_to', $returnTo);

        if ($returnTo !== '') {
            $returnQuery = '?return_to=' . rawurlencode($returnTo);
            $f3->set('about_url', '/about/' . $returnQuery);
            $f3->set('privacy_url', '/privacy/' . $returnQuery);
        }

        echo \Template::instance()->render($template);
    }

    private function staticPageI18n(Base $f3, string $returnTo): I18n
    {
        $requested = strtolower(substr(trim((string) ($f3->get('GET.lang') ?? '')), 0, 2));

        if ($requested === '' && $returnTo !== '') {
            $query = [];
            parse_str((string) (parse_url($returnTo, PHP_URL_QUERY) ?? ''), $query);
            $requested = strtolower(substr(trim((string) ($query['lang'] ?? '')), 0, 2));
        }

        if (in_array($requested, ['en', 'de'], true)) {
            $f3->set('LANGUAGE', $requested);
            return new I18n($f3, $requested);
        }

        return I18n::fromRequest($f3);
    }

    private function loginReturnUrl(Base $f3): string
    {
        $candidate = trim((string) ($f3->get('GET.return_to') ?? ''));

        if ($candidate === '') {
            $candidate = trim((string) ($f3->get('SERVER.HTTP_REFERER') ?? $_SERVER['HTTP_REFERER'] ?? ''));
        }

        if ($candidate === '') {
            return '';
        }

        $parts = parse_url($candidate);

        if ($parts === false) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        $allowedPaths = ['/authorize', '/oauth/email', '/oauth/email/verify'];

        if (!in_array(rtrim($path, '/') ?: '/', $allowedPaths, true)) {
            return '';
        }

        if (isset($parts['host'])) {
            $requestHost = strtolower((string) (
                $f3->get('HOST')
                ?: $f3->get('SERVER.HTTP_HOST')
                ?: ($_SERVER['HTTP_HOST'] ?? '')
            ));
            $candidateHost = strtolower((string) $parts['host']);

            if ($requestHost === '' || $candidateHost !== preg_replace('/:\d+$/', '', $requestHost)) {
                return '';
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));

            if (!in_array($scheme, ['http', 'https'], true)) {
                return '';
            }
        } elseif (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return '';
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $path . $query;
    }

    private function currentUser(mixed $db, array $config = []): ?array
    {
        if (!$db instanceof Db) {
            return null;
        }

        return (new SessionService($db, $config))->currentUser();
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

    private function clientRows(mixed $db, ?array $user, I18n $i18n): array
    {
        if (!$db instanceof Db || $user === null) {
            return [];
        }

        $stmt = $db->pdo()->prepare(
            'SELECT c.id, c.display_name, c.domain, c.branding_json, c.settings_json,
                    connection.scope, connection.connected_at, connection.last_used_at
             FROM auth_user_client_connections connection
             INNER JOIN auth_clients c ON c.id = connection.client_id
             WHERE connection.user_id = :user_id
             ORDER BY connection.last_used_at DESC, c.display_name ASC'
        );
        $stmt->execute(['user_id' => (int) $user['id']]);

        $clientService = new ClientService($db);

        return array_map(function (array $client) use ($clientService, $i18n): array {
            $icon = $this->clientBrandingUrl($client, 'icon');
            $name = (string) $client['display_name'];
            $connectedAt = (string) ($client['connected_at'] ?? '');
            $lastUsedAt = (string) ($client['last_used_at'] ?? '');
            $deletionUrl = $clientService->accountDeletionUrl($client);

            return [
                'id' => (int) $client['id'],
                'detail_url' => '/account/clients/' . (int) $client['id'],
                'display_name' => $name,
                'icon' => $icon,
                'initial' => $this->initial($name),
                'connected_label' => $connectedAt !== ''
                    ? $i18n->t('account.connected_since', [$this->dateLabel($connectedAt, $i18n->language())])
                    : '',
                'last_used_label' => $lastUsedAt !== ''
                    ? $i18n->t('account.last_used', [$this->dateLabel($lastUsedAt, $i18n->language())])
                    : '',
                'shared_data' => $this->sharedDataLabels((string) ($client['scope'] ?? ''), $i18n),
                'deletion_url' => $deletionUrl,
                'deletion_label' => $deletionUrl !== ''
                    ? $i18n->t('account.open_deletion', [$name])
                    : '',
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function clientForUser(Db $db, int $userId, int $clientId): ?array
    {
        if ($userId < 1 || $clientId < 1) {
            return null;
        }

        $stmt = $db->pdo()->prepare(
            'SELECT c.*, connection.scope, connection.connected_at, connection.last_used_at
             FROM auth_user_client_connections connection
             INNER JOIN auth_clients c ON c.id = connection.client_id
             WHERE connection.user_id = :user_id
             AND connection.client_id = :client_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'client_id' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($client) ? $client : null;
    }

    private function clientActionContext(Base $f3, array $params): ?array
    {
        $db = $f3->get('DB');
        $user = $this->currentUser($db, (array) $f3->get('APP_CONFIG'));

        if (!$db instanceof Db || $user === null) {
            $f3->reroute('/account');
            return null;
        }

        $client = $this->clientForUser($db, (int) $user['id'], (int) ($params['id'] ?? 0));

        if ($client === null) {
            $f3->reroute('/account');
            return null;
        }

        return [$user, $client, new SessionService($db, (array) $f3->get('APP_CONFIG'))];
    }

    private function accountI18n(Base $f3, ?array $user, string $returnTo = ''): I18n
    {
        $requested = strtolower(substr(trim((string) ($f3->get('GET.lang') ?? '')), 0, 2));

        if ($requested === '' && $returnTo !== '') {
            $query = [];
            parse_str((string) (parse_url($returnTo, PHP_URL_QUERY) ?? ''), $query);
            $requested = strtolower(substr(trim((string) ($query['lang'] ?? '')), 0, 2));
        }

        $preferred = strtolower(substr(trim((string) ($user['preferred_language'] ?? '')), 0, 2));
        $language = in_array($requested, ['en', 'de'], true)
            ? $requested
            : (in_array($preferred, ['en', 'de'], true) ? $preferred : '');

        if ($language === '') {
            return I18n::fromRequest($f3);
        }

        $f3->set('LANGUAGE', $language);

        return new I18n($f3, $language);
    }

    private function accountUrl(string $returnTo, bool $displayNameAction = false, string $updated = ''): string
    {
        $params = [];

        if ($updated !== '') {
            $params['updated'] = $updated;
        }

        if ($returnTo !== '') {
            $params['return_to'] = $returnTo;
        }

        $path = $displayNameAction ? '/account/display-name' : '/account';

        return $params === [] ? $path : $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function sharedDataLabels(string $scope, I18n $i18n): array
    {
        $scopes = preg_split('/\s+/', strtolower(trim($scope))) ?: [];
        $labels = [$i18n->t('account.data.account_id')];

        if (in_array('email', $scopes, true)) {
            $labels[] = $i18n->t('account.data.email');
        }

        if (in_array('profile', $scopes, true)) {
            $labels[] = $i18n->t('account.data.display_name');
            $labels[] = $i18n->t('account.data.avatar');
            $labels[] = $i18n->t('account.data.language');
        }

        return array_values(array_unique($labels));
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

    private function dateLabel(string $value, string $language = 'en'): string
    {
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return $language === 'de' ? date('d.m.Y', $timestamp) : date('M j, Y', $timestamp);
    }

    private function dateTimeLabel(string $value, string $language = 'en'): string
    {
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return $value;
        }

        return $language === 'de' ? date('d.m.Y, H:i', $timestamp) : date('M j, Y, H:i', $timestamp);
    }

    private function initial(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '?' : strtoupper(substr($value, 0, 1));
    }
}
