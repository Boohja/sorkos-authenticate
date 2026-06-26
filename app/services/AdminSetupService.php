<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

class AdminSetupService
{
    private const USERNAME = 'boohja';
    private const PASSWORD_LENGTH = 40;

    private Db $db;
    private string $rootPath;
    private TotpService $totp;

    public function __construct(Db $db, string $rootPath)
    {
        $this->db = $db;
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->totp = new TotpService();
    }

    public function shouldRedirectToSetup(): bool
    {
        return $this->isUnlocked() && !$this->isLocked() && $this->adminCount() === 0;
    }

    public function createPendingAdmin(): array
    {
        if (!$this->shouldRedirectToSetup()) {
            throw new RuntimeException('Admin setup is not available.');
        }

        $password = $this->randomPassword();
        $secret = $this->totp->createSecret();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO auth_admin_users (username, password_hash, totp_secret_encrypted, is_active, created_at, updated_at)
                 VALUES (:username, :password_hash, :totp_secret, 0, :created_at, :updated_at)'
            );
            $stmt->execute([
                'username' => self::USERNAME,
                'password_hash' => $passwordHash,
                'totp_secret' => $secret,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $adminId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $this->writeLock();
        $this->removeUnlock();

        $_SESSION['setup_admin_id'] = $adminId;
        $_SESSION['setup_admin_password'] = $password;

        return [
            'id' => $adminId,
            'username' => self::USERNAME,
            'password' => $password,
            'totp_secret' => $secret,
        ];
    }

    public function pendingAdminForSession(): ?array
    {
        $adminId = (int) ($_SESSION['setup_admin_id'] ?? 0);

        if ($adminId < 1) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM auth_admin_users WHERE id = :id AND username = :username AND is_active = 0 LIMIT 1'
        );
        $stmt->execute([
            'id' => $adminId,
            'username' => self::USERNAME,
        ]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($admin) ? $admin : null;
    }

    public function activatePendingAdmin(string $code): bool
    {
        $admin = $this->pendingAdminForSession();

        if ($admin === null || empty($admin['totp_secret_encrypted'])) {
            return false;
        }

        if (!$this->totp->verify((string) $admin['totp_secret_encrypted'], $code)) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE auth_admin_users SET is_active = 1, updated_at = :updated_at WHERE id = :id AND is_active = 0'
        );
        $stmt->execute([
            'id' => (int) $admin['id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        unset($_SESSION['setup_admin_id'], $_SESSION['setup_admin_password']);

        return $stmt->rowCount() === 1;
    }

    public function forgetPendingSession(): void
    {
        unset($_SESSION['setup_admin_id'], $_SESSION['setup_admin_password']);
    }

    private function adminCount(): int
    {
        if (!$this->db->isConfigured()) {
            return -1;
        }

        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM auth_admin_users')->fetchColumn();
    }

    private function isUnlocked(): bool
    {
        return is_file($this->rootPath . DIRECTORY_SEPARATOR . 'setup.unlock');
    }

    private function isLocked(): bool
    {
        return is_file($this->rootPath . DIRECTORY_SEPARATOR . 'setup.lock');
    }

    private function writeLock(): void
    {
        file_put_contents(
            $this->rootPath . DIRECTORY_SEPARATOR . 'setup.lock',
            'Admin setup locked at ' . date(DATE_ATOM) . PHP_EOL,
            LOCK_EX
        );
    }

    private function removeUnlock(): void
    {
        $path = $this->rootPath . DIRECTORY_SEPARATOR . 'setup.unlock';

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
        $password = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
