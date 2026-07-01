<?php

use App\Services\AdminAuthService;
use App\Services\Db;

it('does not write sensitive data to output during failed admin login attempts', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(
        'CREATE TABLE auth_admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            totp_secret_encrypted TEXT,
            is_active INTEGER NOT NULL,
            last_login_at TEXT
        )'
    );
    $stmt = $pdo->prepare(
        'INSERT INTO auth_admin_users (username, password_hash, totp_secret_encrypted, is_active)
         VALUES (:username, :password_hash, :totp_secret, 1)'
    );
    $stmt->execute([
        'username' => 'admin',
        'password_hash' => password_hash('correct-password', PASSWORD_DEFAULT),
        'totp_secret' => 'SECRETSECRET',
    ]);

    $db = new class($pdo) extends Db {
        public function __construct(private PDO $pdo)
        {
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function pdo(): PDO
        {
            return $this->pdo;
        }
    };

    ob_start();
    $result = (new AdminAuthService($db))->attempt('admin', 'wrong-password', '000000');
    $output = ob_get_clean();

    expect($result)->toBeFalse()
        ->and($output)->toBe('');
});
