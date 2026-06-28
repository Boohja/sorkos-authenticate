<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Db;
use App\Services\HousekeepingService;
use Base;

class TasksController
{
    public function housekeeping(Base $f3): void
    {
        $config = $f3->get('APP_CONFIG');
        $db = $f3->get('DB');

        if (!$db instanceof Db || !$db->isConfigured()) {
            $this->json(['error' => 'database_not_configured'], 503);
            return;
        }

        $expectedSecret = (string) ($config['tasks']['housekeeping_secret'] ?? '');

        if ($expectedSecret === '') {
            $expectedSecret = (string) ($config['app']['auth_secret'] ?? '');
        }

        if ($expectedSecret === '') {
            $this->json(['error' => 'housekeeping_secret_not_configured'], 503);
            return;
        }

        if (!$this->secretMatches($f3, $expectedSecret)) {
            $this->json(['error' => 'unauthorized'], 401);
            return;
        }

        $result = (new HousekeepingService($db, $config))->run();
        $this->json([
            'ok' => true,
            'deleted' => $result,
        ]);
    }

    private function secretMatches(Base $f3, string $expectedSecret): bool
    {
        $headers = $f3->get('HEADERS') ?: [];
        $provided = (string) ($headers['X-Housekeeping-Secret'] ?? $f3->get('GET.secret') ?? '');
        $authorization = (string) ($headers['Authorization'] ?? '');

        if ($provided === '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $provided = $matches[1];
        }

        return $provided !== '' && hash_equals($expectedSecret, $provided);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
