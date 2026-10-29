<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class SessionService
{
    private const AUTH_COOKIE = 'sorkos_login';
    private const DEFAULT_SESSION_TTL_DAYS = 30;
    private const UNLIMITED_COOKIE_TTL_DAYS = 3650;
    private const PENDING_AUTHORIZE_TTL_SECONDS = 900;

    private Db $db;
    private int $sessionTtlDays;

    public function __construct(Db $db, array $config = [])
    {
        $this->db = $db;
        $configuredTtl = (int) ($config['app']['session_ttl_days'] ?? self::DEFAULT_SESSION_TTL_DAYS);
        $this->sessionTtlDays = $configuredTtl === -1 || $configuredTtl > 0
            ? $configuredTtl
            : self::DEFAULT_SESSION_TTL_DAYS;
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
        $expires = $this->sessionTtlDays === -1
            ? null
            : date('Y-m-d H:i:s', time() + $this->sessionTtlDays * 86400);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_sessions
                (session_hash, user_id, user_agent_hash, session_description, ip_hash, created_at, expires_at)
             VALUES
                (:session_hash, :user_id, :user_agent_hash, :session_description, :ip_hash, :created_at, :expires_at)'
        );
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt->execute([
            'session_hash' => hash('sha256', $token),
            'user_id' => (int) $user['id'],
            'user_agent_hash' => $this->requestHash($userAgent),
            'session_description' => self::describeUserAgent($userAgent),
            'ip_hash' => $this->requestHash((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        setcookie(self::AUTH_COOKIE, $token, [
            'expires' => $this->cookieExpiresAt(),
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::AUTH_COOKIE] = $token;
    }

    public function recordClientUsage(int $userId, int $clientId, ?string $usedAt = null): ?array
    {
        $sessionId = $this->currentSessionId($userId);

        if ($sessionId === null || $clientId < 1) {
            return null;
        }

        $usedAt ??= date('Y-m-d H:i:s');
        $token = 'cst_' . bin2hex(random_bytes(32));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_session_clients
                (session_id, user_id, client_id, client_session_token, created_at, last_used_at, revoked_at)
             VALUES
                (:session_id, :user_id, :client_id, :client_session_token, :created_at, :last_used_at, NULL)
             ON DUPLICATE KEY UPDATE
                last_used_at = IF(revoked_at IS NULL, VALUES(last_used_at), last_used_at)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'client_id' => $clientId,
            'client_session_token' => $token,
            'created_at' => $usedAt,
            'last_used_at' => $usedAt,
        ]);

        $lookup = $this->db->pdo()->prepare(
            'SELECT id, client_session_token
             FROM auth_session_clients
             WHERE session_id = :session_id AND client_id = :client_id
             LIMIT 1'
        );
        $lookup->execute([
            'session_id' => $sessionId,
            'client_id' => $clientId,
        ]);
        $clientSession = $lookup->fetch(PDO::FETCH_ASSOC);

        return is_array($clientSession) ? $clientSession : null;
    }

    public function currentSessionMayUseClient(int $clientId): bool
    {
        $sessionId = $this->currentSessionId();

        if ($sessionId === null || $clientId < 1) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT revoked_at
             FROM auth_session_clients
             WHERE session_id = :session_id AND client_id = :client_id
             LIMIT 1'
        );
        $stmt->execute(['session_id' => $sessionId, 'client_id' => $clientId]);
        $grant = $stmt->fetch(PDO::FETCH_ASSOC);

        return !is_array($grant) || $grant['revoked_at'] === null;
    }

    public function sessionsForClient(int $userId, int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT sc.id, s.session_hash, COALESCE(s.session_description, \'\') AS session_description,
                    sc.created_at, sc.last_used_at
             FROM auth_session_clients sc
             LEFT JOIN auth_sessions s ON s.id = sc.session_id
             WHERE sc.user_id = :user_id
             AND sc.client_id = :client_id
             AND sc.revoked_at IS NULL
             ORDER BY sc.last_used_at DESC, sc.id DESC'
        );
        $stmt->execute(['user_id' => $userId, 'client_id' => $clientId]);
        $currentHash = $this->currentTokenHash();

        return array_map(static function (array $session) use ($currentHash): array {
            $storedHash = (string) ($session['session_hash'] ?? '');
            $session['is_current'] = $currentHash !== '' && $storedHash !== ''
                && hash_equals($storedHash, $currentHash);
            unset($session['session_hash']);
            return $session;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function revokeClientSession(int $userId, int $clientId, int $clientSessionId): bool
    {
        if ($userId < 1 || $clientId < 1 || $clientSessionId < 1) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE auth_session_clients
             SET revoked_at = :revoked_at
             WHERE id = :id
             AND client_id = :client_id
             AND user_id = :user_id
             AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => date('Y-m-d H:i:s'),
            'id' => $clientSessionId,
            'client_id' => $clientId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function revokeAllClientSessions(int $userId, int $clientId): int
    {
        if ($userId < 1 || $clientId < 1) {
            return 0;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE auth_session_clients
             SET revoked_at = :revoked_at
             WHERE client_id = :client_id
             AND user_id = :user_id
             AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => date('Y-m-d H:i:s'),
            'client_id' => $clientId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount();
    }

    public function clientSessionActive(int $clientId, string $clientSessionToken): bool
    {
        if (
            $clientId < 1
            || preg_match('/^cst_[a-f0-9]{64}$/', $clientSessionToken) !== 1
        ) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
             FROM auth_session_clients sc
             INNER JOIN auth_users u ON u.id = sc.user_id
             INNER JOIN auth_clients c ON c.id = sc.client_id
             INNER JOIN auth_user_client_connections connection
                ON connection.user_id = sc.user_id
                AND connection.client_id = sc.client_id
             WHERE sc.client_id = :client_id
             AND sc.client_session_token = :client_session_token
             AND sc.revoked_at IS NULL
             AND u.disabled_at IS NULL
             AND c.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'client_session_token' => $clientSessionToken,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public static function describeUserAgent(string $userAgent): string
    {
        if (trim($userAgent) === '') {
            return 'Unknown browser';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $device = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => '',
        };

        return $device === '' ? $browser : $browser . ' on ' . $device;
    }

    public function currentUser(): ?array
    {
        $token = (string) ($_COOKIE[self::AUTH_COOKIE] ?? '');

        if ($token === '' || !$this->db->isConfigured()) {
            return null;
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT u.*, s.expires_at AS auth_session_expires_at
                 FROM auth_sessions s INNER JOIN auth_users u ON u.id = s.user_id
                 WHERE s.session_hash = :session_hash
                 AND s.revoked_at IS NULL
                 AND (s.expires_at IS NULL OR s.expires_at > NOW())
                 AND u.disabled_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['session_hash' => hash('sha256', $token)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return null;
        }

        if (!is_array($user)) {
            return null;
        }

        if ($this->sessionTtlDays === -1 && $user['auth_session_expires_at'] !== null) {
            $update = $this->db->pdo()->prepare(
                'UPDATE auth_sessions SET expires_at = NULL WHERE session_hash = :session_hash'
            );
            $update->execute(['session_hash' => hash('sha256', $token)]);
            setcookie(self::AUTH_COOKIE, $token, [
                'expires' => $this->cookieExpiresAt(),
                'path' => '/',
                'secure' => $this->isSecureRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        unset($user['auth_session_expires_at']);
        return $user;
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

    private function currentSessionId(?int $userId = null): ?int
    {
        $sessionHash = $this->currentTokenHash();

        if ($sessionHash === '' || !$this->db->isConfigured()) {
            return null;
        }

        $sql = 'SELECT id FROM auth_sessions
                WHERE session_hash = :session_hash
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())';
        $params = ['session_hash' => $sessionHash];

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function currentTokenHash(): string
    {
        $token = (string) ($_COOKIE[self::AUTH_COOKIE] ?? '');
        return $token === '' ? '' : hash('sha256', $token);
    }

    private function cookieExpiresAt(): int
    {
        $ttlDays = $this->sessionTtlDays === -1
            ? self::UNLIMITED_COOKIE_TTL_DAYS
            : $this->sessionTtlDays;

        return time() + $ttlDays * 86400;
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
