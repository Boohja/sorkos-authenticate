<?php

declare(strict_types=1);

namespace App\Services;

class AuthorizationCodeService
{
    private const CODE_TTL_MINUTES = 10;

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function issue(array $pendingAuthorize, array $user): string
    {
        $code = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::CODE_TTL_MINUTES * 60);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_authorization_codes
                (code_hash, client_id, user_id, redirect_uri, scope, created_at, expires_at)
             VALUES
                (:code_hash, :client_id, :user_id, :redirect_uri, :scope, :created_at, :expires_at)'
        );
        $stmt->execute([
            'code_hash' => hash('sha256', $code),
            'client_id' => (int) $pendingAuthorize['client_pk'],
            'user_id' => (int) $user['id'],
            'redirect_uri' => (string) $pendingAuthorize['redirect_uri'],
            'scope' => (string) ($pendingAuthorize['scope'] ?? ''),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        return $code;
    }

    public function callbackUrl(array $pendingAuthorize, string $code): string
    {
        return $this->appendParams((string) $pendingAuthorize['redirect_uri'], [
            'code' => $code,
            'state' => (string) ($pendingAuthorize['state'] ?? ''),
        ]);
    }

    private function appendParams(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }
}
