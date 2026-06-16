<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ClientService;
use Base;
use PDOException;

class DevController
{
    public function testClient(Base $f3): void
    {
        $config = $f3->get('APP_CONFIG');
        $clientId = (string) ($config['dev_client']['client_id'] ?? 'local-test');
        $redirectUri = (string) ($config['dev_client']['redirect_uri'] ?? rtrim((string) $config['app']['base_url'], '/') . '/dev/callback');
        $state = bin2hex(random_bytes(16));

        $_SESSION['dev_client_state'] = $state;

        $authorizeUrl = '/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'profile email',
            'state' => $state,
            'lang' => (string) ($f3->get('GET.lang') ?: 'en'),
        ]);

        $seedSql = $this->seedSql($clientId, $redirectUri);

        $f3->set('title', 'Local Auth Flow Test');
        $f3->set('html_lang', 'en');
        $f3->set('client_id', $clientId);
        $f3->set('redirect_uri', $redirectUri);
        $f3->set('authorize_url', $authorizeUrl);
        $f3->set('seed_sql', $seedSql);
        $f3->set('dev_seed_status', (string) ($_SESSION['dev_seed_status'] ?? ''));
        unset($_SESSION['dev_seed_status']);
        echo \Template::instance()->render('dev_test_client.html');
    }

    public function seedTestClient(Base $f3): void
    {
        $config = $f3->get('APP_CONFIG');
        $clientId = (string) ($config['dev_client']['client_id'] ?? 'local-test');
        $redirectUri = (string) ($config['dev_client']['redirect_uri'] ?? rtrim((string) $config['app']['base_url'], '/') . '/dev/callback');

        try {
            $db = $f3->get('DB')->pdo();
            $now = date('Y-m-d H:i:s');

            $stmt = $db->prepare(
                'INSERT INTO auth_clients
                 (client_id, name, display_name, domain, default_language, allowed_languages, enabled_providers, is_confidential, is_active, created_at, updated_at)
                 VALUES
                 (:client_id, :name, :display_name, :domain, :default_language, :allowed_languages, :enabled_providers, 1, 1, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                 display_name = VALUES(display_name),
                 allowed_languages = VALUES(allowed_languages),
                 enabled_providers = VALUES(enabled_providers),
                 updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                'client_id' => $clientId,
                'name' => 'local-test',
                'display_name' => 'Local Test Client',
                'domain' => parse_url($redirectUri, PHP_URL_HOST) ?: 'auth.test',
                'default_language' => 'en',
                'allowed_languages' => 'en,de',
                'enabled_providers' => 'google,discord',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $clientStmt = $db->prepare('SELECT id FROM auth_clients WHERE client_id = :client_id LIMIT 1');
            $clientStmt->execute(['client_id' => $clientId]);
            $clientPk = (int) $clientStmt->fetchColumn();
            $redirectStmt = $db->prepare(
                'INSERT INTO auth_client_redirect_uris (client_id, redirect_uri, created_at)
                 SELECT :client_id, :redirect_uri, :created_at
                 WHERE NOT EXISTS (
                   SELECT 1 FROM auth_client_redirect_uris WHERE client_id = :client_id_check AND redirect_uri = :redirect_uri_check
                 )'
            );
            $redirectStmt->execute([
                'client_id' => $clientPk,
                'redirect_uri' => $redirectUri,
                'created_at' => $now,
                'client_id_check' => $clientPk,
                'redirect_uri_check' => $redirectUri,
            ]);

            $_SESSION['dev_seed_status'] = 'Local test client is ready.';
        } catch (PDOException $exception) {
            $_SESSION['dev_seed_status'] = 'Could not seed local test client: ' . $exception->getMessage();
        }

        $f3->reroute('/dev/test-client');
    }

    public function callback(Base $f3): void
    {
        $expectedState = (string) ($_SESSION['dev_client_state'] ?? '');
        $receivedState = (string) ($f3->get('GET.state') ?? '');

        $f3->set('title', 'Local Auth Callback');
        $f3->set('html_lang', 'en');
        $f3->set('code', (string) ($f3->get('GET.code') ?? ''));
        $f3->set('expected_state', $expectedState);
        $f3->set('received_state', $receivedState);
        $f3->set('state_ok', $expectedState !== '' && hash_equals($expectedState, $receivedState));
        echo \Template::instance()->render('dev_callback.html');
    }

    private function seedSql(string $clientId, string $redirectUri): string
    {
        $now = date('Y-m-d H:i:s');
        $client = ClientService::quoteSqlString($clientId);
        $redirect = ClientService::quoteSqlString($redirectUri);

        return "INSERT INTO auth_clients (client_id, name, display_name, domain, default_language, allowed_languages, enabled_providers, is_confidential, is_active, created_at, updated_at)\n"
            . "VALUES ({$client}, 'local-test', 'Local Test Client', 'auth.test', 'en', 'en,de', 'google,discord', 1, 1, '{$now}', '{$now}')\n"
            . "ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), allowed_languages = VALUES(allowed_languages), enabled_providers = VALUES(enabled_providers), updated_at = VALUES(updated_at);\n\n"
            . "INSERT INTO auth_client_redirect_uris (client_id, redirect_uri, created_at)\n"
            . "SELECT id, {$redirect}, '{$now}' FROM auth_clients WHERE client_id = {$client}\n"
            . "AND NOT EXISTS (\n"
            . "  SELECT 1 FROM auth_client_redirect_uris WHERE redirect_uri = {$redirect}\n"
            . "  AND client_id = (SELECT id FROM auth_clients WHERE client_id = {$client})\n"
            . ");";
    }
}
