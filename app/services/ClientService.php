<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class ClientService
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function validateAuthorizeRequest(array $query): array
    {
        $clientId = trim((string) ($query['client_id'] ?? ''));
        $redirectUri = trim((string) ($query['redirect_uri'] ?? ''));
        $responseType = trim((string) ($query['response_type'] ?? ''));
        $state = trim((string) ($query['state'] ?? ''));

        if ($clientId === '') {
            return $this->invalid('error.invalid_client');
        }

        if ($redirectUri === '') {
            return $this->invalid('error.invalid_redirect_uri');
        }

        if ($state === '') {
            return $this->invalid('error.missing_state');
        }

        $client = $this->findActiveByClientId($clientId);

        if ($client === null) {
            return $this->invalid('error.invalid_client');
        }

        if (!$this->responseTypeAllowed($responseType)) {
            return $this->invalid('error.unsupported_response_type');
        }

        if (!$this->redirectUriAllowed((int) $client['id'], $redirectUri)) {
            return $this->invalid('error.invalid_redirect_uri');
        }

        return [
            'ok' => true,
            'client' => $client,
            'error' => null,
        ];
    }

    public function findActiveByClientId(string $clientId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM auth_clients WHERE client_id = :client_id AND is_active = 1 LIMIT 1');
        $stmt->execute(['client_id' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($client) ? $client : null;
    }

    public function secretValid(array $client, string $secret): bool
    {
        if (empty($client['is_confidential'])) {
            return true;
        }

        $hash = (string) ($client['client_secret_hash'] ?? '');
        $valid = $hash !== '' && $secret !== '' && password_verify($secret, $hash);

        if ($valid
            && empty($client['request_signing_key'])
            && (int) ($client['id'] ?? 0) > 0
            && $this->db->isConfigured()
        ) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE auth_clients
                 SET request_signing_key = :request_signing_key
                 WHERE id = :id AND request_signing_key IS NULL'
            );
            $stmt->execute([
                'request_signing_key' => ClientRequestSigner::deriveKey($secret),
                'id' => (int) $client['id'],
            ]);
        }

        return $valid;
    }

    public function redirectUriAllowed(int $clientPk, string $redirectUri): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM auth_client_redirect_uris WHERE client_id = :client_id AND redirect_uri = :redirect_uri'
        );
        $stmt->execute([
            'client_id' => $clientPk,
            'redirect_uri' => $redirectUri,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function responseTypeAllowed(string $responseType): bool
    {
        return $responseType === 'code';
    }

    public function accountDeletionUrl(array $client): string
    {
        $url = trim((string) ($this->settings($client)['account_deletion_url'] ?? ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    public function sessionRevocationUrl(array $client): string
    {
        $url = trim((string) ($this->settings($client)['session_revocation_url'] ?? ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return $scheme === 'https' ? $url : '';
    }

    public static function csvToList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    public static function quoteSqlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function settings(array $client): array
    {
        if (empty($client['settings_json'])) {
            return [];
        }

        $decoded = json_decode((string) $client['settings_json'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private function invalid(string $error): array
    {
        return [
            'ok' => false,
            'client' => null,
            'error' => $error,
        ];
    }
}
