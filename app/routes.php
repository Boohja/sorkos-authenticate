<?php

declare(strict_types=1);

$f3 = Base::instance();

$f3->route('GET /', function (Base $f3): void {
    (new App\Controllers\HomeController())->index($f3);
});

$f3->route('GET /authorize', function (Base $f3): void {
    (new App\Controllers\AuthController())->authorize($f3);
});

$f3->route('GET /dev/test-client', function (Base $f3): void {
    (new App\Controllers\DevController())->testClient($f3);
});

$f3->route('POST /dev/test-client/seed', function (Base $f3): void {
    (new App\Controllers\DevController())->seedTestClient($f3);
});

$f3->route('GET /dev/callback', function (Base $f3): void {
    (new App\Controllers\DevController())->callback($f3);
});
