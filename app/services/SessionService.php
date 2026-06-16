<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class SessionService
{
    private const AUTH_COOKIE = 'comasu_auth';

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function storePendingAuthorizeRequest(array $request): void
    {
        $_SESSION['pending_authorize'] = $request;
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
}
