<?php

declare(strict_types=1);

namespace App\Services;

class HousekeepingService
{
    private Db $db;
    private array $config;

    public function __construct(Db $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function run(): array
    {
        $emailCodeCutoff = $this->cutoffDateTime('email_code_retention_hours', 24, 'hours');
        $authorizationCodeCutoff = $this->cutoffDateTime('authorization_code_retention_hours', 24, 'hours');
        $sessionCutoff = $this->cutoffDateTime('session_retention_days', 7, 'days');

        return [
            'email_login_codes' => $this->deleteExpiredOrConsumedEmailCodes($emailCodeCutoff),
            'authorization_codes' => $this->deleteExpiredOrUsedAuthorizationCodes($authorizationCodeCutoff),
            'sessions' => $this->deleteExpiredOrRevokedSessions($sessionCutoff),
        ];
    }

    private function deleteExpiredOrConsumedEmailCodes(string $cutoff): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM auth_email_login_codes
             WHERE expires_at < :expires_cutoff
             OR (consumed_at IS NOT NULL AND consumed_at < :consumed_cutoff)'
        );
        $stmt->execute([
            'expires_cutoff' => $cutoff,
            'consumed_cutoff' => $cutoff,
        ]);

        return $stmt->rowCount();
    }

    private function deleteExpiredOrUsedAuthorizationCodes(string $cutoff): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM auth_authorization_codes
             WHERE expires_at < :expires_cutoff
             OR (used_at IS NOT NULL AND used_at < :used_cutoff)'
        );
        $stmt->execute([
            'expires_cutoff' => $cutoff,
            'used_cutoff' => $cutoff,
        ]);

        return $stmt->rowCount();
    }

    private function deleteExpiredOrRevokedSessions(string $cutoff): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM auth_sessions
             WHERE expires_at < :expires_cutoff
             OR (revoked_at IS NOT NULL AND revoked_at < :revoked_cutoff)'
        );
        $stmt->execute([
            'expires_cutoff' => $cutoff,
            'revoked_cutoff' => $cutoff,
        ]);

        return $stmt->rowCount();
    }

    private function cutoffDateTime(string $configKey, int $default, string $unit): string
    {
        $tasks = $this->config['tasks'] ?? [];
        $amount = max(1, (int) ($tasks[$configKey] ?? $default));
        $seconds = $unit === 'days' ? $amount * 86400 : $amount * 3600;

        return date('Y-m-d H:i:s', time() - $seconds);
    }
}
