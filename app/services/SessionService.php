<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class SessionService
{
    private const AUTH_COOKIE = 'sorkos_login';
    private const SESSION_TTL_DAYS = 30;
    private const PENDING_AUTHORIZE_TTL_SECONDS = 900;

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function storePendingAuthorizeRequest(array $request): void
    {
        unset($_SESSION['pending_authorize_expired']);
        $_SESSION['pending_authorize'] = $request;
    }

    public function pendingAuthorizeRequest(): ?array
    {
        $request = $_SESSION['pending_authorize'] ?? null;

        if (!is_array($request)) {
            return null;
        }

        $createdAt = (int) ($request['created_at'] ?? 0);

        if ($createdAt <= 0 || $createdAt + self::PENDING_AUTHORIZE_TTL_SECONDS < time()) {
            $_SESSION['pending_authorize_expired'] = true;
            $this->clearPendingAuthorizeRequest();
            return null;
        }

        return $request;
    }

    public function pendingAuthorizeExpired(): bool
    {
        $expired = !empty($_SESSION['pending_authorize_expired']);
        unset($_SESSION['pending_authorize_expired']);

        return $expired;
    }

    public function clearPendingAuthorizeRequest(): void
    {
        unset($_SESSION['pending_authorize']);
        unset($_SESSION['email_login_challenge']);
        unset($_SESSION['email_login_mail_sent']);
    }

    public function pendingAuthorizeUrl(): string
    {
        $request = $this->pendingAuthorizeRequest();

        if ($request === null) {
            return '/authorize';
        }

        $query = [
            'client_id' => $request['client_id'] ?? '',
            'redirect_uri' => $request['redirect_uri'] ?? '',
            'response_type' => $request['response_type'] ?? 'code',
            'state' => $request['state'] ?? '',
            'scope' => $request['scope'] ?? '',
            'prompt' => $request['prompt'] ?? '',
            'lang' => $request['lang'] ?? '',
        ];
        $query = array_filter($query, static fn ($value): bool => (string) $value !== '');

        return '/authorize?' . http_build_query($query);
    }

    public function createSession(array $user): void
    {
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_TTL_DAYS * 86400);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_sessions
                (session_hash, user_id, user_agent_hash, ip_hash, created_at, expires_at)
             VALUES
                (:session_hash, :user_id, :user_agent_hash, :ip_hash, :created_at, :expires_at)'
        );
        $stmt->execute([
            'session_hash' => hash('sha256', $token),
            'user_id' => (int) $user['id'],
            'user_agent_hash' => $this->requestHash((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'ip_hash' => $this->requestHash((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        setcookie(self::AUTH_COOKIE, $token, [
            'expires' => time() + self::SESSION_TTL_DAYS * 86400,
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::AUTH_COOKIE] = $token;
    }

    public function currentUser(): ?array
    {
        $token = (string) ($_COOKIE[self::AUTH_COOKIE] ?? '');

        if ($token === '' || !$this->db->isConfigured()) {
            return null;
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT u.* FROM auth_sessions s INNER JOIN auth_users u ON u.id = s.user_id
                 WHERE s.session_hash = :session_hash
                 AND s.revoked_at IS NULL
                 AND s.expires_at > NOW()
                 AND u.disabled_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['session_hash' => hash('sha256', $token)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return null;
        }

        return is_array($user) ? $user : null;
    }

    public function revokeCurrentSession(): bool
    {
        $token = (string) ($_COOKIE[self::AUTH_COOKIE] ?? '');
        $revoked = false;

        if ($token !== '' && $this->db->isConfigured()) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE auth_sessions
                 SET revoked_at = :revoked_at
                 WHERE session_hash = :session_hash
                 AND revoked_at IS NULL'
            );
            $stmt->execute([
                'revoked_at' => date('Y-m-d H:i:s'),
                'session_hash' => hash('sha256', $token),
            ]);
            $revoked = $stmt->rowCount() > 0;
        }

        setcookie(self::AUTH_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::AUTH_COOKIE]);

        return $revoked;
    }

    private function requestHash(string $value): ?string
    {
        return $value === '' ? null : hash('sha256', $value);
    }

    private function isSecureRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
