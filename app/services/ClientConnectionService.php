<?php

declare(strict_types=1);

namespace App\Services;

class ClientConnectionService
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function recordSuccessfulLogin(int $userId, int $clientId, string $scope, ?string $usedAt = null): void
    {
        if ($userId < 1 || $clientId < 1) {
            throw new \InvalidArgumentException('A valid user and client are required.');
        }

        $usedAt ??= date('Y-m-d H:i:s');
        $scope = $this->normalizeScope($scope);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_user_client_connections
                (user_id, client_id, scope, connected_at, last_used_at)
             VALUES
                (:user_id, :client_id, :scope, :connected_at, :last_used_at)
             ON DUPLICATE KEY UPDATE
                scope = VALUES(scope),
                last_used_at = VALUES(last_used_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId,
            'scope' => $scope !== '' ? $scope : null,
            'connected_at' => $usedAt,
            'last_used_at' => $usedAt,
        ]);
    }

    public function revokeForClient(int $clientId, string $publicUserId): void
    {
        $publicUserId = trim($publicUserId);

        if ($clientId < 1 || $publicUserId === '') {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'DELETE connection
             FROM auth_user_client_connections connection
             INNER JOIN auth_users user ON user.id = connection.user_id
             WHERE connection.client_id = :client_id
             AND user.public_id = :public_user_id'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'public_user_id' => $publicUserId,
        ]);
    }

    private function normalizeScope(string $scope): string
    {
        $items = preg_split('/\s+/', trim($scope)) ?: [];
        $items = array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));

        return substr(implode(' ', $items), 0, 255);
    }
}
