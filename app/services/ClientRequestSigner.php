<?php

declare(strict_types=1);

namespace App\Services;

class ClientRequestSigner
{
    private const VERSION = 'v1';
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public static function deriveKey(string $clientSecret): string
    {
        return hash('sha256', $clientSecret);
    }

    public static function canSign(array $client): bool
    {
        return !empty($client['is_confidential'])
            && trim((string) ($client['client_id'] ?? '')) !== ''
            && preg_match('/^[a-f0-9]{64}$/', (string) ($client['request_signing_key'] ?? '')) === 1;
    }

    public static function signature(
        array $client,
        string $method,
        string $url,
        string $body,
        int $timestamp,
        string $nonce
    ): string {
        if (!self::canSign($client)) {
            return '';
        }

        $canonical = implode("
", [
            self::VERSION,
            strtoupper($method),
            self::requestTarget($url),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return self::VERSION . '=' . hash_hmac(
            'sha256',
            $canonical,
            (string) $client['request_signing_key']
        );
    }

    public function postJson(array $client, string $url, array $payload): bool
    {
        if (!self::canSign($client) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        if (strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? '')) !== 'https') {
            return false;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($body)) {
            return false;
        }

        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $signature = self::signature($client, 'POST', $url, $body, $timestamp, $nonce);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Sorkos-Auth/1.0',
            'X-Sorkos-Client-Id: ' . (string) $client['client_id'],
            'X-Sorkos-Timestamp: ' . $timestamp,
            'X-Sorkos-Nonce: ' . $nonce,
            'X-Sorkos-Signature: ' . $signature,
        ];
        $status = is_callable($this->transport)
            ? (int) ($this->transport)($url, $body, $headers)
            : $this->send($url, $body, $headers);

        return $status >= 200 && $status < 300;
    }

    private function send(string $url, string $body, array $headers): int
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("
", $headers) . "
",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        return self::responseStatus($responseHeaders);
    }

    public static function responseStatus(array $headers): int
    {
        return isset($headers[0])
            && preg_match('/\s(\d{3})(?:\s|$)/', (string) $headers[0], $matches)
            ? (int) $matches[1]
            : 0;
    }

    public static function requestTarget(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '/';
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path !== '' ? $path : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $path . $query;
    }
}
