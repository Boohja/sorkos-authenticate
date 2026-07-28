<?php

it('renders enabled email login as a link and unavailable providers as disabled buttons', function (): void {
    $html = $this->renderView('login.html', [
        'title' => 'Example Login with Sorkos',
        'html_lang' => 'en',
        'active_nav' => '',
        'layout_variant' => 'split',
        'hide_split_header' => true,
        'client' => [
            'display_name' => 'Example App',
        ],
        'client_logo' => '',
        'providers' => [
            [
                'name' => 'email',
                'label' => 'Continue with E-Mail',
                'href' => '/oauth/email',
                'enabled' => true,
                'icon' => '',
            ],
            [
                'name' => 'google',
                'label' => 'Continue with Google',
                'href' => '',
                'enabled' => false,
                'icon' => '',
            ],
        ],
    ]);

    expect($html)->toContain('Example App')
        ->and($html)->toContain('href="/oauth/email"')
        ->and($html)->toContain('Continue with E-Mail')
        ->and($html)->toContain('Continue with Google')
        ->and($html)->toContain('aria-disabled="true" disabled')
        ->and($html)->toContain('What is Sorkos Login?')
        ->and($html)->not->toContain('Google and Discord are visible only as configured placeholders for now.');
});

it('renders the email address form with validation hooks and back navigation', function (): void {
    $html = $this->renderView('email_login.html', [
        'title' => 'Email Login',
        'html_lang' => 'en',
        'active_nav' => '',
        'layout_variant' => 'split',
        'hide_split_header' => true,
        'client' => ['display_name' => 'Example App'],
        'email_value' => 'user@example.com',
        'email_error_key' => 'email.invalid',
        'back_url' => '/authorize?client_id=example',
    ]);

    expect($html)->toContain('Sign in with E-Mail')
        ->and($html)->toContain('method="post" action="/oauth/email"')
        ->and($html)->toContain('name="email" type="email"')
        ->and($html)->toContain('value="user@example.com"')
        ->and($html)->toContain('Enter a valid email address.')
        ->and($html)->toContain('href="/authorize?client_id=example"');
});

it('renders six one-time-code inputs and preserves signed email flow parameters', function (): void {
    $html = $this->renderView('email_code.html', [
        'title' => 'Verify Email Login',
        'html_lang' => 'en',
        'active_nav' => '',
        'layout_variant' => 'split',
        'hide_split_header' => true,
        'client' => ['display_name' => 'Example App'],
        'code_sent_email' => 'user@example.com',
        'code_error_key' => 'email.code_invalid',
        'prefill_code' => '123456',
        'mail_sent' => false,
        'email_flow' => [
            'flow' => 'signed-flow',
            'sig' => 'signed-sig',
        ],
        'back_url' => '/oauth/email',
    ]);

    expect(substr_count($html, 'name="email_code_digit[]"'))->toBe(6)
        ->and($html)->toContain('data-code-form')
        ->and($html)->toContain('name="email_code_full" value="123456"')
        ->and($html)->toContain('name="flow" value="signed-flow"')
        ->and($html)->toContain('name="sig" value="signed-sig"')
        ->and($html)->toContain('The email could not be sent.')
        ->and($html)->toContain('The code is invalid, expired, or used too often.');
});

it('renders a logged-out account page without destructive account actions enabled', function (): void {
    $html = $this->renderView('account.html', [
        'title' => 'Account',
        'html_lang' => 'en',
        'active_nav' => '',
        'account_user' => null,
    ]);

    expect($html)->toContain('No active Sorkos session')
        ->and($html)->toContain('Back to landing page')
        ->and($html)->not->toContain('Delete Sorkos account');
});

it('renders account actions and shared interaction feedback hooks', function (): void {
    $html = $this->renderView('home.html', [
        'title' => 'Sorkos Login',
        'html_lang' => 'en',
        'active_nav' => 'home',
        'layout_variant' => 'split',
        'hide_split_header' => false,
        'current_user' => ['id' => 1],
        'current_user_label' => 'Matt Manage',
    ]);

    expect($html)->toContain('data-transition-to-standard')
        ->and($html)->toContain('method="post" action="/logout"')
        ->and($html)->toContain('Log out')
        ->and($html)->toContain('/js/app.js?v=20260726-pending-motion');
});

it('renders a safe return to the authorization flow on static information pages', function (): void {
    $f3 = $this->app();
    $f3->set('HOST', 'auth.test');
    $f3->set('SERVER.HTTP_REFERER', 'https://auth.test/authorize?client_id=pulse&state=state-123');

    ob_start();
    (new App\Controllers\HomeController())->privacy($f3);
    $html = (string) ob_get_clean();

    expect($html)->toContain('href="https://sorkos.net"')
        ->and($html)->toContain('Back to sign in')
        ->and($html)->toContain('href="/authorize?client_id=pulse&amp;state=state-123"');
});

it('does not render a return button for an external referrer', function (): void {
    $f3 = $this->app();
    $f3->set('HOST', 'auth.test');
    $f3->set('SERVER.HTTP_REFERER', 'https://example.com/authorize?client_id=pulse');

    ob_start();
    (new App\Controllers\HomeController())->about($f3);
    $html = (string) ob_get_clean();

    expect($html)->toContain('href="https://sorkos.net"')
        ->and($html)->toContain('Minimal data')
        ->and($html)->toContain('See the data privacy section for details.')
        ->and($html)->not->toContain('Back to sign in');
});

it('uses the preserved login language for About and Privacy pages', function (): void {
    $f3 = $this->app();
    $f3->set('GET.return_to', '/authorize?client_id=pulse&state=state-123&lang=de');

    ob_start();
    (new App\Controllers\HomeController())->about($f3);
    $aboutHtml = (string) ob_get_clean();

    expect($aboutHtml)->toContain('<html lang="de">')
        ->and($aboutHtml)->toContain('Über Sorkos Login')
        ->and($aboutHtml)->toContain('Minimale Daten')
        ->and($aboutHtml)->toContain('Details findest du im Datenschutzbereich.')
        ->and($aboutHtml)->toContain('Zurück zur Anmeldung');

    ob_start();
    (new App\Controllers\HomeController())->privacy($f3);
    $privacyHtml = (string) ob_get_clean();

    expect($privacyHtml)->toContain('<html lang="de">')
        ->and($privacyHtml)->toContain('<h1>Datenschutz</h1>')
        ->and($privacyHtml)->toContain('Wir speichern so wenige Daten wie möglich')
        ->and($privacyHtml)->toContain('Deine Daten löschen');
});
