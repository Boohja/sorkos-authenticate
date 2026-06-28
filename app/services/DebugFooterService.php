<?php

declare(strict_types=1);

namespace App\Services;

use Base;
use PDO;
use PDOException;

class DebugFooterService
{
    private const AUTH_COOKIE = 'sorkos_login';

    public static function html(Base $f3): string
    {
        $config = $f3->get('APP_CONFIG') ?: [];

        if (empty($config['app']['debug_footer'])) {
            return '';
        }

        $authCookie = (string) ($_COOKIE[self::AUTH_COOKIE] ?? '');
        $pending = $_SESSION['pending_authorize'] ?? null;
        $challenge = $_SESSION['email_login_challenge'] ?? null;
        $dbSession = self::dbSession($f3, $authCookie);

        return '<div class="debug-footer" aria-label="Debug footer">'
            . '<div class="debug-footer-heading">'
            . '<strong>Debug</strong>'
            . '<span>Visible because <code>app.debug_footer</code> is enabled.</span>'
            . '</div>'
            . '<div class="debug-grid">'
            . self::runtimeSection($f3)
            . self::pendingAuthorizeSection($pending)
            . self::emailChallengeSection($f3, $challenge)
            . self::centralSessionSection($f3, $authCookie, $dbSession)
            . self::phpSessionSection()
            . '</div>'
            . '</div>';
    }

    private static function runtimeSection(Base $f3): string
    {
        return self::section('Runtime', [
            self::row('time', date('c')),
            self::row('language', (string) ($f3->get('LANGUAGE') ?: '')),
            self::row('method', (string) ($f3->get('VERB') ?: ($_SERVER['REQUEST_METHOD'] ?? ''))),
            self::row('php session', session_name() . '=' . session_id(), ''),
        ]);
    }

    private static function pendingAuthorizeSection(mixed $pending): string
    {
        if (!is_array($pending)) {
            return self::section('Client Authorization', [
                self::emptyRow('No pending authorization request in this PHP session.'),
            ]);
        }

        return self::section('Client Authorization', [
            self::row('client_id', $pending['client_id'] ?? null),
            self::row('client_pk', $pending['client_pk'] ?? null),
            self::row('redirect_uri', $pending['redirect_uri'] ?? null),
            self::row('response_type', $pending['response_type'] ?? null),
            self::row('scope', $pending['scope'] ?? null),
            self::row('state', $pending['state'] ?? null, 'Client-provided OAuth state. It is kept in PHP session only and sent back to the client; it is not a DB lookup key here.'),
            self::row('lang', $pending['lang'] ?? null),
            self::row('created_at', self::timestamp($pending['created_at'] ?? null)),
        ]);
    }

    private static function emailChallengeSection(Base $f3, mixed $challenge): string
    {
        if (!is_array($challenge)) {
            return self::section('Email Challenge', [
                self::emptyRow('No email code challenge in this PHP session.'),
            ]);
        }

        $rows = [
            self::row('session challenge id', $challenge['id'] ?? null, 'Matches auth_email_login_codes.id.'),
            self::row('email', $challenge['email'] ?? null),
            self::row('client_pk', $challenge['client_pk'] ?? null),
        ];

        $dbRow = self::emailChallengeDbRow($f3, (int) ($challenge['id'] ?? 0));

        if ($dbRow === null) {
            $rows[] = self::emptyRow('No matching auth_email_login_codes row found.');
        } elseif (isset($dbRow['lookup'])) {
            $rows[] = self::row('db lookup', $dbRow['lookup'] . ': ' . ($dbRow['reason'] ?? $dbRow['error'] ?? ''));
        } else {
            $rows[] = self::row('db row', 'auth_email_login_codes #' . $dbRow['id']);
            $rows[] = self::row('selector_hash', self::prefix((string) $dbRow['selector_hash']));
            $rows[] = self::row('code_hash', self::prefix((string) $dbRow['code_hash']));
            $rows[] = self::row('attempts', $dbRow['attempts']);
            $rows[] = self::row('created_at', $dbRow['created_at']);
            $rows[] = self::row('expires_at', $dbRow['expires_at']);
            $rows[] = self::row('consumed_at', $dbRow['consumed_at']);
        }

        $identity = self::emailIdentity($f3, (string) ($challenge['email'] ?? ''));

        if (is_array($identity) && !isset($identity['lookup'])) {
            $rows[] = self::row('email identity', 'identity #' . $identity['identity_id'] . ', user #' . $identity['user_id']);
            $rows[] = self::row('user', ($identity['email'] ?: $identity['public_id']) . ' / ' . $identity['public_id']);
        }

        return self::section('Email Challenge', $rows);
    }

    private static function centralSessionSection(Base $f3, string $authCookie, ?array $dbSession): string
    {
        $rows = [
            self::row('cookie', self::AUTH_COOKIE),
            self::row('cookie present', $authCookie !== '' ? 'yes' : 'no'),
        ];

        if ($authCookie !== '') {
            $rows[] = self::row('cookie hash', self::prefix(hash('sha256', $authCookie)), 'This is what auth_sessions.session_hash stores. Raw cookie value is intentionally not printed.');
        }

        if ($dbSession === null) {
            $rows[] = self::emptyRow('No central login cookie, so no DB session lookup.');
        } elseif (isset($dbSession['lookup'])) {
            $rows[] = self::row('db lookup', $dbSession['lookup'] . ': ' . ($dbSession['reason'] ?? $dbSession['error'] ?? ''));
        } else {
            $rows[] = self::row('db row', 'auth_sessions #' . $dbSession['id']);
            $rows[] = self::row('user_id', $dbSession['user_id']);
            $rows[] = self::row('user', ($dbSession['email'] ?: $dbSession['public_id']) . ' / ' . $dbSession['public_id']);
            $rows[] = self::row('created_at', $dbSession['created_at']);
            $rows[] = self::row('expires_at', $dbSession['expires_at']);
            $rows[] = self::row('revoked_at', $dbSession['revoked_at']);

            foreach (self::identitiesForUser($f3, (int) $dbSession['user_id']) as $identity) {
                $rows[] = self::row(
                    'identity',
                    $identity['provider'] . ' #' . $identity['id'] . ' / ' . $identity['provider_user_id']
                );
            }
        }

        return self::section('Central Login Session', $rows);
    }

    private static function phpSessionSection(): string
    {
        $session = $_SESSION;
        unset(
            $session['pending_authorize'],
            $session['pending_authorize_expired'],
            $session['email_login_challenge'],
            $session['email_login_mail_sent']
        );

        if ($session === []) {
            return self::section('Other PHP Session Data', [
                self::emptyRow('No other session data.'),
            ]);
        }

        $rows = [];

        foreach ($session as $key => $value) {
            $rows[] = self::row((string) $key, self::displayValue($value));
        }

        return self::section('Other PHP Session Data', $rows);
    }

    private static function dbSession(Base $f3, string $authCookie): ?array
    {
        if ($authCookie === '') {
            return null;
        }

        $db = self::db($f3);

        if (!$db instanceof Db) {
            return self::dbUnavailable();
        }

        try {
            $stmt = $db->pdo()->prepare(
                'SELECT s.id, s.user_id, s.created_at, s.expires_at, s.revoked_at, u.public_id, u.email
                 FROM auth_sessions s
                 LEFT JOIN auth_users u ON u.id = s.user_id
                 WHERE s.session_hash = :session_hash
                 LIMIT 1'
            );
            $stmt->execute(['session_hash' => hash('sha256', $authCookie)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return [
                'lookup' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        return is_array($row) ? $row : [
            'lookup' => 'not_found',
        ];
    }

    private static function emailChallengeDbRow(Base $f3, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db($f3);

        if (!$db instanceof Db) {
            return self::dbUnavailable();
        }

        try {
            $stmt = $db->pdo()->prepare('SELECT * FROM auth_email_login_codes WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return [
                'lookup' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        return is_array($row) ? $row : null;
    }

    private static function emailIdentity(Base $f3, string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        $db = self::db($f3);

        if (!$db instanceof Db) {
            return self::dbUnavailable();
        }

        try {
            $stmt = $db->pdo()->prepare(
                'SELECT i.id AS identity_id, i.user_id, i.provider, i.provider_user_id, u.public_id, u.email
                 FROM auth_identities i
                 INNER JOIN auth_users u ON u.id = i.user_id
                 WHERE i.provider = :provider
                 AND i.provider_user_id = :provider_user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'provider' => 'email',
                'provider_user_id' => $email,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            return [
                'lookup' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }

        return is_array($row) ? $row : null;
    }

    private static function identitiesForUser(Base $f3, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = self::db($f3);

        if (!$db instanceof Db) {
            return [];
        }

        try {
            $stmt = $db->pdo()->prepare(
                'SELECT id, provider, provider_user_id, provider_email
                 FROM auth_identities
                 WHERE user_id = :user_id
                 ORDER BY provider, id'
            );
            $stmt->execute(['user_id' => $userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
            return [];
        }
    }

    private static function db(Base $f3): ?Db
    {
        $db = $f3->get('DB');

        return $db instanceof Db && $db->isConfigured() ? $db : null;
    }

    private static function dbUnavailable(): array
    {
        return [
            'lookup' => 'skipped',
            'reason' => 'database is not configured',
        ];
    }

    private static function section(string $title, array $rows): string
    {
        return '<section class="debug-card">'
            . '<h2>' . self::escape($title) . '</h2>'
            . '<dl>' . implode('', $rows) . '</dl>'
            . '</section>';
    }

    private static function row(string $label, mixed $value, string $hint = ''): string
    {
        $html = '<dt>' . self::escape($label) . '</dt>'
            . '<dd><code>' . self::escape(self::displayValue($value)) . '</code>';

        if ($hint !== '') {
            $html .= '<span>' . self::escape($hint) . '</span>';
        }

        return $html . '</dd>';
    }

    private static function emptyRow(string $message): string
    {
        return '<dt>status</dt><dd><span>' . self::escape($message) . '</span></dd>';
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '[unprintable]';
    }

    private static function timestamp(mixed $value): string
    {
        $timestamp = (int) $value;

        if ($timestamp <= 0) {
            return 'null';
        }

        return date('c', $timestamp) . ' (' . $timestamp . ')';
    }

    private static function prefix(string $value): string
    {
        return substr($value, 0, 16) . '...';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
