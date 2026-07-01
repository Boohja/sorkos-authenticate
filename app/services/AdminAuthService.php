<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class AdminAuthService
{
    private Db $db;
    private TotpService $totp;

    public function __construct(Db $db)
    {
        $this->db = $db;
        $this->totp = new TotpService();
    }

    public function attempt(string $username, string $password, string $otp): bool
    {
        $admin = $this->findActiveByUsername($username);

        if ($admin === null) {
            return false;
        }

        if (!password_verify($password, (string) $admin['password_hash'])) {
            return false;
        }

        if (empty($admin['totp_secret_encrypted']) || !$this->totp->verify((string) $admin['totp_secret_encrypted'], $otp)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = (string) $admin['username'];
        $_SESSION['admin_authenticated_at'] = time();

        $stmt = $this->db->pdo()->prepare('UPDATE auth_admin_users SET last_login_at = :last_login_at WHERE id = :id');
        $stmt->execute([
            'id' => (int) $admin['id'],
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function currentAdmin(): ?array
    {
        $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);

        if ($adminId < 1) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare('SELECT id, username, last_login_at FROM auth_admin_users WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($admin) ? $admin : null;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_username'], $_SESSION['admin_authenticated_at']);
        session_regenerate_id(true);
    }

    private function findActiveByUsername(string $username): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM auth_admin_users WHERE username = :username AND is_active = 1 LIMIT 1');
        $stmt->execute(['username' => trim($username)]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($admin) ? $admin : null;
    }
}
