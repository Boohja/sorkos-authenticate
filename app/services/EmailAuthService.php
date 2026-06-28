<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class EmailAuthService
{
    private const PROVIDER = 'email';
    private const CODE_TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_EMAIL_CLIENT_CODES_PER_HOUR = 5;
    private const MAX_CLIENT_CODES_PER_HOUR = 30;

    private Db $db;
    private array $config;

    public function __construct(Db $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function createCode(string $email, int $clientId): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $selector = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::CODE_TTL_MINUTES * 60);

        $this->expireActiveCodesForEmailClient($email, $clientId, $now);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_email_login_codes
                (selector_hash, code_hash, email, client_id, attempts, created_at, expires_at)
             VALUES
                (:selector_hash, :code_hash, :email, :client_id, 0, :created_at, :expires_at)'
        );
        $stmt->execute([
            'selector_hash' => $this->hashSelector($selector),
            'code_hash' => $this->hashCode($code),
            'email' => $email,
            'client_id' => $clientId,
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        return [
            'id' => (int) $this->db->pdo()->lastInsertId(),
            'selector' => $selector,
            'code' => $code,
            'expires_at' => $expires,
        ];
    }

    public function codeRequestStatus(string $email, int $clientId): array
    {
        $recentCutoff = date('Y-m-d H:i:s', time() - self::RESEND_COOLDOWN_SECONDS);
        $hourCutoff = date('Y-m-d H:i:s', time() - 3600);

        $recentStmt = $this->db->pdo()->prepare(
            'SELECT created_at FROM auth_email_login_codes
             WHERE email = :email
             AND client_id = :client_id
             AND created_at > :recent_cutoff
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $recentStmt->execute([
            'email' => $email,
            'client_id' => $clientId,
            'recent_cutoff' => $recentCutoff,
        ]);
        $recentCreatedAt = $recentStmt->fetchColumn();

        if (is_string($recentCreatedAt) && $recentCreatedAt !== '') {
            $retryAfter = self::RESEND_COOLDOWN_SECONDS - max(0, time() - strtotime($recentCreatedAt));

            return [
                'ok' => false,
                'error' => 'email.rate_limited_recent',
                'retry_after' => max(1, $retryAfter),
            ];
        }

        $emailCount = $this->countCodesSince(
            'email = :email AND client_id = :client_id',
            [
                'email' => $email,
                'client_id' => $clientId,
                'created_after' => $hourCutoff,
            ]
        );

        if ($emailCount >= self::MAX_EMAIL_CLIENT_CODES_PER_HOUR) {
            return [
                'ok' => false,
                'error' => 'email.rate_limited_email',
                'retry_after' => 3600,
            ];
        }

        $clientCount = $this->countCodesSince(
            'client_id = :client_id',
            [
                'client_id' => $clientId,
                'created_after' => $hourCutoff,
            ]
        );

        if ($clientCount >= self::MAX_CLIENT_CODES_PER_HOUR) {
            return [
                'ok' => false,
                'error' => 'email.rate_limited_client',
                'retry_after' => 3600,
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'retry_after' => 0,
        ];
    }

    public function sendCode(string $email, string $code, string $continueUrl, I18n $i18n): bool
    {
        $subject = $i18n->t('email.mail_subject');
        $body = $i18n->t('email.mail_body', [
            'code' => $code,
            'url' => $continueUrl,
        ]);

        $headers = [
            'From: Sorkos Login <login@sorkos.net>',
            'Reply-To: login@sorkos.net',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        return mail($email, $subject, $body, implode("\r\n", $headers));
    }

    public function verifyCode(int $id, string $selector, string $code): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM auth_email_login_codes
                 WHERE id = :id
                 AND selector_hash = :selector_hash
                 AND consumed_at IS NULL
                 AND expires_at > NOW()
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                'id' => $id,
                'selector_hash' => $this->hashSelector($selector),
            ]);
            $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($challenge)) {
                $pdo->rollBack();
                return null;
            }

            if ((int) $challenge['attempts'] >= self::MAX_ATTEMPTS) {
                $pdo->rollBack();
                return null;
            }

            if (!hash_equals((string) $challenge['code_hash'], $this->hashCode($code))) {
                $update = $pdo->prepare('UPDATE auth_email_login_codes SET attempts = attempts + 1 WHERE id = :id');
                $update->execute(['id' => $id]);
                $pdo->commit();
                return null;
            }

            $update = $pdo->prepare('UPDATE auth_email_login_codes SET consumed_at = :consumed_at WHERE id = :id');
            $update->execute([
                'id' => $id,
                'consumed_at' => date('Y-m-d H:i:s'),
            ]);
            $pdo->commit();

            return $challenge;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function createOrUpdateUser(string $email, string $language): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $now = date('Y-m-d H:i:s');

        try {
            $identityStmt = $pdo->prepare(
                'SELECT u.* FROM auth_identities i
                 INNER JOIN auth_users u ON u.id = i.user_id
                 WHERE i.provider = :provider
                 AND i.provider_user_id = :provider_user_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $identityStmt->execute([
                'provider' => self::PROVIDER,
                'provider_user_id' => $email,
            ]);
            $user = $identityStmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($user)) {
                $this->updateUserLogin((int) $user['id'], $email, $language, $now);
                $pdo->commit();
                return $this->findUser((int) $user['id']);
            }

            $publicId = $this->createPublicId();
            $insertUser = $pdo->prepare(
                'INSERT INTO auth_users
                    (public_id, email, email_verified, display_name, avatar_url, preferred_language, created_at, updated_at, last_login_at)
                 VALUES
                    (:public_id, :email, 1, :display_name, NULL, :preferred_language, :created_at, :updated_at, :last_login_at)'
            );
            $insertUser->execute([
                'public_id' => $publicId,
                'email' => $email,
                'display_name' => $email,
                'preferred_language' => $language,
                'created_at' => $now,
                'updated_at' => $now,
                'last_login_at' => $now,
            ]);
            $userId = (int) $pdo->lastInsertId();

            $insertIdentity = $pdo->prepare(
                'INSERT INTO auth_identities
                    (user_id, provider, provider_user_id, provider_email, provider_email_verified, created_at, updated_at)
                 VALUES
                    (:user_id, :provider, :provider_user_id, :provider_email, 1, :created_at, :updated_at)'
            );
            $insertIdentity->execute([
                'user_id' => $userId,
                'provider' => self::PROVIDER,
                'provider_user_id' => $email,
                'provider_email' => $email,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $pdo->commit();
            return $this->findUser($userId);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function updateUserLogin(int $userId, string $email, string $language, string $now): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE auth_users
             SET email = :email,
                 email_verified = 1,
                 preferred_language = :preferred_language,
                 updated_at = :updated_at,
                 last_login_at = :last_login_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'email' => $email,
            'preferred_language' => $language,
            'updated_at' => $now,
            'last_login_at' => $now,
        ]);
    }

    private function findUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM auth_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : [];
    }

    private function createPublicId(): string
    {
        do {
            $publicId = 'usr_' . bin2hex(random_bytes(12));
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM auth_users WHERE public_id = :public_id');
            $stmt->execute(['public_id' => $publicId]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $publicId;
    }

    private function expireActiveCodesForEmailClient(string $email, int $clientId, string $now): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE auth_email_login_codes
             SET consumed_at = :consumed_at
             WHERE email = :email
             AND client_id = :client_id
             AND consumed_at IS NULL
             AND expires_at > :expires_after'
        );
        $stmt->execute([
            'consumed_at' => $now,
            'expires_after' => $now,
            'email' => $email,
            'client_id' => $clientId,
        ]);
    }

    private function countCodesSince(string $where, array $params): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM auth_email_login_codes
             WHERE ' . $where . '
             AND created_at > :created_after'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->hmacKey());
    }

    private function hashSelector(string $selector): string
    {
        return hash('sha256', $selector);
    }

    private function hmacKey(): string
    {
        $secret = (string) ($this->config['app']['auth_secret'] ?? '');

        if ($secret !== '') {
            return $secret;
        }

        return (string) (($this->config['db']['password'] ?? '') ?: __DIR__);
    }
}
