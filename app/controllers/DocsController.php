<?php

declare(strict_types=1);

namespace App\Controllers;

use Base;

class DocsController
{
    public function index(Base $f3): void
    {
        $this->render($f3, 'Documentation - Sorkos Login', 'docs.html');
    }

    public function api(Base $f3): void
    {
        $this->render($f3, 'API - Sorkos Login', 'docs_api.html');
    }

    public function openapi(Base $f3): void
    {
        $path = dirname(__DIR__) . '/openapi.json';

        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'openapi_not_found']);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        readfile($path);
    }

    public function workflow(Base $f3): void
    {
        $this->render($f3, 'Workflow - Sorkos Login', 'docs_workflow.html');
    }

    private function render(Base $f3, string $title, string $template): void
    {
        $f3->set('title', 'Documentation - Sorkos Login');
        $f3->set('title', $title);
        $f3->set('html_lang', 'en');
        $f3->set('active_nav', 'docs');
        $f3->set('request_origin', $this->requestOrigin($f3));
        echo \Template::instance()->render($template);
    }

    private function requestOrigin(Base $f3): string
    {
        $headers = $f3->get('HEADERS') ?: [];
        $server = $f3->get('SERVER') ?: [];

        $scheme = strtolower((string) ($headers['X-Forwarded-Proto'] ?? ''));

        if ($scheme === '') {
            $scheme = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off'
                ? 'https'
                : (string) ($f3->get('SCHEME') ?: 'http');
        }

        $host = (string) ($headers['X-Forwarded-Host'] ?? $headers['Host'] ?? $f3->get('HOST'));
        $host = trim(explode(',', $host)[0]);

        if ($host === '') {
            $host = 'localhost';
        }

        return rtrim($scheme . '://' . $host, '/');
    }
}
