<?php

declare(strict_types=1);

$f3 = Base::instance();

$f3->route('GET /', function (Base $f3): void {
    (new App\Controllers\HomeController())->index($f3);
});

$f3->route(['GET /docs', 'GET /docs/'], function (Base $f3): void {
    (new App\Controllers\DocsController())->index($f3);
});

$f3->route(['GET /about', 'GET /about/'], function (Base $f3): void {
    (new App\Controllers\HomeController())->about($f3);
});

$f3->route(['GET /privacy', 'GET /privacy/'], function (Base $f3): void {
    (new App\Controllers\HomeController())->privacy($f3);
});

$f3->route('GET /docs/api', function (Base $f3): void {
    (new App\Controllers\DocsController())->api($f3);
});

$f3->route('GET /docs/openapi.json', function (Base $f3): void {
    (new App\Controllers\DocsController())->openapi($f3);
});

$f3->route('GET /docs/workflow', function (Base $f3): void {
    (new App\Controllers\DocsController())->workflow($f3);
});

$f3->route('GET /authorize', function (Base $f3): void {
    (new App\Controllers\AuthController())->authorize($f3);
});

$f3->route('GET /oauth/email', function (Base $f3): void {
    (new App\Controllers\AuthController())->emailForm($f3);
});

$f3->route('POST /oauth/email', function (Base $f3): void {
    (new App\Controllers\AuthController())->sendEmailCode($f3);
});

$f3->route('GET /oauth/email/verify', function (Base $f3): void {
    (new App\Controllers\AuthController())->emailCodeForm($f3);
});

$f3->route('POST /oauth/email/verify', function (Base $f3): void {
    (new App\Controllers\AuthController())->verifyEmailCode($f3);
});

$f3->route('GET /setup', function (Base $f3): void {
    (new App\Controllers\SetupController())->show($f3);
});

$f3->route('POST /setup/verify', function (Base $f3): void {
    (new App\Controllers\SetupController())->verify($f3);
});

$f3->route('GET /admin', function (Base $f3): void {
    $f3->reroute('/admin/clients');
});

$f3->route('GET /admin/login', function (Base $f3): void {
    (new App\Controllers\AdminController())->loginForm($f3);
});

$f3->route('POST /admin/login', function (Base $f3): void {
    (new App\Controllers\AdminController())->login($f3);
});

$f3->route('POST /admin/logout', function (Base $f3): void {
    (new App\Controllers\AdminController())->logout($f3);
});

$f3->route('GET /admin/clients', function (Base $f3): void {
    (new App\Controllers\AdminController())->clients($f3);
});

$f3->route('GET /admin/clients/@id', function (Base $f3): void {
    (new App\Controllers\AdminController())->clientDetail($f3);
});

$f3->route('POST /admin/clients/@id/secret', function (Base $f3): void {
    (new App\Controllers\AdminController())->updateClientSecret($f3);
});

$f3->route('POST /admin/clients/@id/redirect-uris', function (Base $f3): void {
    (new App\Controllers\AdminController())->addRedirectUri($f3);
});

$f3->route('POST /admin/clients/@id/redirect-uris/@redirect_id/delete', function (Base $f3): void {
    (new App\Controllers\AdminController())->deleteRedirectUri($f3);
});
