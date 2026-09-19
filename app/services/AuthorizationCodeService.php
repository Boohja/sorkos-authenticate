<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class AuthorizationCodeService
{
    private const CODE_TTL_MINUTES = 10;

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function issue(array $pendingAuthorize, array $user, int $sessionClientId): string
    {
        $code = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::CODE_TTL_MINUTES * 60);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_authorization_codes
                (code_hash, client_id, user_id, session_client_id, redirect_uri, scope, created_at, expires_at)
             VALUES
                (:code_hash, :client_id, :user_id, :session_client_id, :redirect_uri, :scope, :created_at, :expires_at)'
        );
        $stmt->execute([
            'code_hash' => hash('sha256', $code),
            'client_id' => (int) $pendingAuthorize['client_pk'],
            'user_id' => (int) $user['id'],
            'session_client_id' => $sessionClientId,
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

    public function redeem(string $code, int $clientPk, string $redirectUri): ?array
    {
        if ($code === '' || $clientPk <= 0 || $redirectUri === '') {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, sc.client_session_token,
                        u.public_id, u.email, u.email_verified, u.display_name, u.avatar_url, u.preferred_language
                 FROM auth_authorization_codes c
                 INNER JOIN auth_session_clients sc
                    ON sc.id = c.session_client_id
                    AND sc.client_id = c.client_id
                    AND sc.user_id = c.user_id
                 INNER JOIN auth_users u ON u.id = c.user_id
                 WHERE c.code_hash = :code_hash
                 AND c.client_id = :client_id
                 AND c.redirect_uri = :redirect_uri
                 AND c.used_at IS NULL
                 AND c.expires_at > NOW()
                 AND sc.revoked_at IS NULL
                 AND u.disabled_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([
                'code_hash' => hash('sha256', $code),
                'client_id' => $clientPk,
                'redirect_uri' => $redirectUri,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $pdo->rollBack();
                return null;
            }

            $usedAt = date('Y-m-d H:i:s');
            $update = $pdo->prepare(
                'UPDATE auth_authorization_codes
                 SET used_at = :used_at
                 WHERE id = :id AND used_at IS NULL'
            );
            $update->execute([
                'used_at' => $usedAt,
                'id' => (int) $row['id'],
            ]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }

            (new ClientConnectionService($this->db))->recordSuccessfulLogin(
                (int) $row['user_id'],
                $clientPk,
                (string) ($row['scope'] ?? ''),
                $usedAt
            );

            $pdo->commit();

            $scopes = preg_split('/\s+/', strtolower(trim((string) ($row['scope'] ?? '')))) ?: [];
            $sharesEmail = in_array('email', $scopes, true);
            $sharesProfile = in_array('profile', $scopes, true);

            return [
                'client_session_token' => (string) $row['client_session_token'],
                'user' => [
                    'id' => (string) $row['public_id'],
                    'email' => $sharesEmail && $row['email'] !== null ? (string) $row['email'] : null,
                    'email_verified' => $sharesEmail && (bool) $row['email_verified'],
                    'display_name' => $sharesProfile && $row['display_name'] !== null ? (string) $row['display_name'] : null,
                    'avatar_url' => $sharesProfile && $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
                    'preferred_language' => $sharesProfile && $row['preferred_language'] !== null
                        ? (string) $row['preferred_language']
                        : null,
                ],
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function appendParams(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }
}