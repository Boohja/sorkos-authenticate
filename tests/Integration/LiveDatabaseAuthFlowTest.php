<?php

use App\Services\AuthorizationCodeService;
use App\Services\ClientService;
use App\Services\Db;
use App\Services\EmailAuthService;

beforeEach(function (): void {
    if (getenv('SORKOS_LIVE_DB_TESTS') !== '1') {
        $this->markTestSkipped('Set SORKOS_LIVE_DB_TESTS=1 to run live database integration tests.');
    }

    $this->config = require dirname(__DIR__, 2) . '/app/config/config.php';

    if (empty($this->config['db']['enabled'])) {
        $this->markTestSkipped('Database integration tests require db.enabled=true.');
    }

    $this->db = new Db($this->config['db']);
    $this->pdo = $this->db->pdo();
    $this->marker = 'pest_' . bin2hex(random_bytes(8));
    $this->clientId = $this->marker . '_client';
    $this->redirectUri = 'https://client.example.test/callback/' . $this->marker;
    $this->email = $this->marker . '@example.test';
    $this->publicId = 'usr_' . $this->marker;
});

afterEach(function (): void {
    if (!isset($this->pdo, $this->marker)) {
        return;
    }

    liveDbCleanup($this->pdo, $this->marker);
});

it('validates an authorize request and redeems an authorization code exactly once', function (): void {
    $clientPk = liveDbCreateClient($this->pdo, $this->marker, $this->clientId, $this->redirectUri);
    $userId = liveDbCreateUser($this->pdo, $this->marker, $this->publicId, $this->email);
    $clients = new ClientService($this->db);

    $validation = $clients->validateAuthorizeRequest([
        'client_id' => $this->clientId,
        'redirect_uri' => $this->redirectUri,
        'response_type' => 'code',
        'state' => 'client-state',
    ]);

    expect($validation['ok'])->toBeTrue()
        ->and($validation['client']['id'])->toBe($clientPk);

    $codes = new AuthorizationCodeService($this->db);
    $code = $codes->issue([
        'client_pk' => $clientPk,
        'redirect_uri' => $this->redirectUri,
        'scope' => 'profile email',
        'state' => 'client-state',
    ], [
        'id' => $userId,
    ]);

    $redeemed = $codes->redeem($code, $clientPk, $this->redirectUri);

    expect($redeemed)->toMatchArray([
        'id' => $this->publicId,
        'email' => $this->email,
        'email_verified' => true,
        'display_name' => 'Pest Test User',
        'preferred_language' => 'en',
    ])
        ->and($codes->redeem($code, $clientPk, $this->redirectUri))->toBeNull()
        ->and($codes->redeem($code, $clientPk, $this->redirectUri . '/wrong'))->toBeNull();
});

it('creates hashed email login codes, verifies them once, and upserts email identities', function (): void {
    $clientPk = liveDbCreateClient($this->pdo, $this->marker, $this->clientId, $this->redirectUri);
    $service = new EmailAuthService($this->db, $this->config);

    $challenge = $service->createCode($this->email, $clientPk);
    $row = liveDbFindEmailCode($this->pdo, (int) $challenge['id']);

    expect($challenge['code'])->toMatch('/^\d{6}$/')
        ->and($row['code_hash'])->not->toBe($challenge['code'])
        ->and($row['selector_hash'])->not->toBe($challenge['selector']);

    expect($service->verifyCode((int) $challenge['id'], $challenge['selector'], '000000'))->toBeNull();

    $attempts = (int) liveDbFindEmailCode($this->pdo, (int) $challenge['id'])['attempts'];

    expect($attempts)->toBe(1);

    $verified = $service->verifyCode((int) $challenge['id'], $challenge['selector'], $challenge['code']);

    expect($verified)->toBeArray()
        ->and($verified['email'])->toBe($this->email)
        ->and($service->verifyCode((int) $challenge['id'], $challenge['selector'], $challenge['code']))->toBeNull();

    $user = $service->createOrUpdateUser($this->email, 'en');
    $sameUser = $service->createOrUpdateUser($this->email, 'de');

    expect($user['email'])->toBe($this->email)
        ->and((bool) $user['email_verified'])->toBeTrue()
        ->and($sameUser['id'])->toBe($user['id'])
        ->and($sameUser['preferred_language'])->toBe('de');
});

function liveDbCreateClient(PDO $pdo, string $marker, string $clientId, string $redirectUri): int
{
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO auth_clients
            (client_id, client_secret_hash, name, display_name, domain, default_language, allowed_languages,
             enabled_providers, branding_json, settings_json, is_confidential, is_active, created_at, updated_at)
         VALUES
            (:client_id, :client_secret_hash, :name, :display_name, :domain, "en", "en,de",
             "email,google,discord", NULL, :settings_json, 1, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        'client_id' => $clientId,
        'client_secret_hash' => password_hash('pest-secret-' . $marker, PASSWORD_DEFAULT),
        'name' => $marker . ' Client',
        'display_name' => 'Pest Client ' . $marker,
        'domain' => 'client.example.test',
        'settings_json' => json_encode(['response_types' => ['code']]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $clientPk = (int) $pdo->lastInsertId();

    $redirect = $pdo->prepare(
        'INSERT INTO auth_client_redirect_uris (client_id, redirect_uri, created_at)
         VALUES (:client_id, :redirect_uri, :created_at)'
    );
    $redirect->execute([
        'client_id' => $clientPk,
        'redirect_uri' => $redirectUri,
        'created_at' => $now,
    ]);

    return $clientPk;
}

function liveDbCreateUser(PDO $pdo, string $marker, string $publicId, string $email): int
{
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO auth_users
            (public_id, email, email_verified, display_name, avatar_url, preferred_language,
             created_at, updated_at, last_login_at, disabled_at)
         VALUES
            (:public_id, :email, 1, "Pest Test User", NULL, "en", :created_at, :updated_at, NULL, NULL)'
    );
    $stmt->execute([
        'public_id' => $publicId,
        'email' => $email,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $identity = $pdo->prepare(
        'INSERT INTO auth_identities
            (user_id, provider, provider_user_id, provider_email, provider_email_verified, created_at, updated_at)
         VALUES
            (:user_id, "email", :provider_user_id, :provider_email, 1, :created_at, :updated_at)'
    );
    $identity->execute([
        'user_id' => $userId,
        'provider_user_id' => $email,
        'provider_email' => $email,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $userId;
}

function liveDbFindEmailCode(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM auth_email_login_codes WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function liveDbCleanup(PDO $pdo, string $marker): void
{
    $clientLike = $marker . '%';
    $email = $marker . '@example.test';
    $publicId = 'usr_' . $marker;

    $clientIds = liveDbIds($pdo, 'SELECT id FROM auth_clients WHERE client_id LIKE :marker', ['marker' => $clientLike]);
    $userIds = liveDbIds($pdo, 'SELECT id FROM auth_users WHERE public_id = :public_id OR email = :email', [
        'public_id' => $publicId,
        'email' => $email,
    ]);

    liveDbDeleteByIds($pdo, 'auth_sessions', 'user_id', $userIds);
    liveDbDeleteByIds($pdo, 'auth_authorization_codes', 'client_id', $clientIds);
    liveDbDeleteByIds($pdo, 'auth_authorization_codes', 'user_id', $userIds);
    liveDbDeleteByIds($pdo, 'auth_user_client_consents', 'client_id', $clientIds);
    liveDbDeleteByIds($pdo, 'auth_user_client_consents', 'user_id', $userIds);
    liveDbDeleteByIds($pdo, 'auth_email_login_codes', 'client_id', $clientIds);

    $stmt = $pdo->prepare('DELETE FROM auth_email_login_codes WHERE email = :email');
    $stmt->execute(['email' => $email]);

    liveDbDeleteByIds($pdo, 'auth_client_redirect_uris', 'client_id', $clientIds);
    liveDbDeleteByIds($pdo, 'auth_identities', 'user_id', $userIds);

    $stmt = $pdo->prepare('DELETE FROM auth_users WHERE public_id = :public_id OR email = :email');
    $stmt->execute([
        'public_id' => $publicId,
        'email' => $email,
    ]);

    $stmt = $pdo->prepare('DELETE FROM auth_clients WHERE client_id LIKE :marker');
    $stmt->execute(['marker' => $clientLike]);
}

function liveDbIds(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function liveDbDeleteByIds(PDO $pdo, string $table, string $column, array $ids): void
{
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(sprintf('DELETE FROM %s WHERE %s IN (%s)', $table, $column, $placeholders));
    $stmt->execute($ids);
}
