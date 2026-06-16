<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class Db
{
    private array $config;
    private ?PDO $pdo = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['enabled'])
            && !empty($this->config['host'])
            && !empty($this->config['database'])
            && !empty($this->config['username']);
    }

    public function pdo(): PDO
    {
        if (!$this->isConfigured()) {
            throw new PDOException('Database is not configured.');
        }

        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $charset = $this->config['charset'] ?? 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'] ?? '3306',
            $this->config['database'],
            $charset
        );

        $this->pdo = new PDO($dsn, (string) $this->config['username'], (string) ($this->config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->pdo;
    }
}
