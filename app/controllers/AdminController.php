<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminAuthService;
use Base;
use PDO;

class AdminController
{
    public function loginForm(Base $f3): void
    {
        if ($this->auth($f3)->currentAdmin() !== null) {
            $f3->reroute('/admin/clients');
            return;
        }

        $this->renderLogin($f3, false);
    }

    public function login(Base $f3): void
    {
        $auth = $this->auth($f3);

        if ($auth->attempt(
            (string) $f3->get('POST.username'),
            (string) $f3->get('POST.password'),
            (string) $f3->get('POST.otp')
        )) {
            $f3->reroute('/admin/clients');
            return;
        }

        $this->renderLogin($f3, true);
    }

    public function logout(Base $f3): void
    {
        $this->auth($f3)->logout();
        $f3->reroute('/admin/login');
    }

    public function clients(Base $f3): void
    {
        $admin = $this->requireAdmin($f3);

        if ($admin === null) {
            return;
        }

        $stmt = $f3->get('DB')->pdo()->query(
            'SELECT c.id, c.client_id, c.name, c.display_name, c.domain, c.enabled_providers, c.is_active, c.updated_at,
                    (SELECT COUNT(*) FROM auth_client_redirect_uris r WHERE r.client_id = c.id) AS redirect_uri_count
             FROM auth_clients c
             ORDER BY c.display_name ASC, c.client_id ASC'
        );

        $f3->set('title', 'Admin Clients');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', 'admin');
        $f3->set('admin', $admin);
        $f3->set('clients', $stmt->fetchAll(PDO::FETCH_ASSOC));
        echo \Template::instance()->render('admin_clients.html');
    }

    public function clientDetail(Base $f3): void
    {
        $admin = $this->requireAdmin($f3);

        if ($admin === null) {
            return;
        }

        $clientId = (int) $f3->get('PARAMS.id');
        $db = $f3->get('DB');
        $stmt = $db->pdo()->prepare('SELECT * FROM auth_clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($client)) {
            $f3->error(404);
            return;
        }

        $redirectStmt = $db->pdo()->prepare('SELECT id, redirect_uri, created_at FROM auth_client_redirect_uris WHERE client_id = :client_id ORDER BY id ASC');
        $redirectStmt->execute(['client_id' => $clientId]);

        $f3->set('title', 'Admin Client');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', 'admin');
        $f3->set('admin', $admin);
        $f3->set('client', $client);
        $f3->set('redirect_uris', $redirectStmt->fetchAll(PDO::FETCH_ASSOC));
        echo \Template::instance()->render('admin_client_detail.html');
    }

    public function updateClientSecret(Base $f3): void
    {
        $admin = $this->requireAdmin($f3);

        if ($admin === null) {
            return;
        }

        $clientId = (int) $f3->get('PARAMS.id');
        $secret = (string) $f3->get('POST.client_secret');

        if (trim($secret) !== '') {
            $stmt = $f3->get('DB')->pdo()->prepare(
                'UPDATE auth_clients SET client_secret_hash = :client_secret_hash, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                'id' => $clientId,
                'client_secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $f3->reroute('/admin/clients/' . $clientId);
    }

    public function addRedirectUri(Base $f3): void
    {
        $admin = $this->requireAdmin($f3);

        if ($admin === null) {
            return;
        }

        $clientId = (int) $f3->get('PARAMS.id');
        $redirectUri = trim((string) $f3->get('POST.redirect_uri'));

        if ($redirectUri !== '') {
            $stmt = $f3->get('DB')->pdo()->prepare(
                'INSERT INTO auth_client_redirect_uris (client_id, redirect_uri, created_at) VALUES (:client_id, :redirect_uri, :created_at)'
            );
            $stmt->execute([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $f3->reroute('/admin/clients/' . $clientId);
    }

    public function deleteRedirectUri(Base $f3): void
    {
        $admin = $this->requireAdmin($f3);

        if ($admin === null) {
            return;
        }

        $clientId = (int) $f3->get('PARAMS.id');
        $redirectId = (int) $f3->get('PARAMS.redirect_id');
        $stmt = $f3->get('DB')->pdo()->prepare(
            'DELETE FROM auth_client_redirect_uris WHERE id = :id AND client_id = :client_id'
        );
        $stmt->execute([
            'id' => $redirectId,
            'client_id' => $clientId,
        ]);

        $f3->reroute('/admin/clients/' . $clientId);
    }

    private function renderLogin(Base $f3, bool $invalid): void
    {
        $f3->set('title', 'Admin Login');
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', 'admin');
        $f3->set('invalid_login', $invalid);
        echo \Template::instance()->render('admin_login.html');
    }

    private function requireAdmin(Base $f3): ?array
    {
        $admin = $this->auth($f3)->currentAdmin();

        if ($admin === null) {
            $f3->reroute('/admin/login');
            return null;
        }

        return $admin;
    }

    private function auth(Base $f3): AdminAuthService
    {
        return new AdminAuthService($f3->get('DB'));
    }
}
