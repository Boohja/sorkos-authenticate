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

it('renders connection timestamps, shared data, and the configured project deletion link', function (): void {
    $html = $this->renderView('account.html', [
        'title' => 'Account',
        'html_lang' => 'en',
        'active_nav' => '',
        'account_user' => [
            'display_name' => 'Matt',
            'email' => 'matt@example.test',
        ],
        'account_user_label' => 'Matt',
        'account_user_initial' => 'M',
        'account_avatar_url' => '',
        'account_provider_label' => 'E-Mail',
        'account_show_identity_details' => false,
        'account_can_edit_display_name' => true,
        'account_display_name_updated' => false,
        'account_has_clients' => true,
        'account_display_name_action' => '/account/display-name?return_to=%2Fauthorize%3Fclient_id%3Dpulse',
        'account_return_url' => '/authorize?client_id=pulse',
        'account_return_label' => 'Back to sign in',
        'account_clients' => [[
            'display_name' => 'Local Pulse',
            'icon' => 'https://pulse.test/icon.svg',
            'initial' => 'L',
            'connected_label' => 'Connected since Jul 3, 2026',
            'last_used_label' => 'Last used Jul 28, 2026',
            'shared_data' => ['Sorkos account ID', 'Email address'],
            'deletion_url' => 'https://pulse.test/account/delete',
            'deletion_label' => 'Open account deletion in Local Pulse',
        ]],
    ]);

    expect($html)->toContain('Connected since Jul 3, 2026')
        ->and($html)->toContain('Last used Jul 28, 2026')
        ->and($html)->toContain('src="https://pulse.test/icon.svg"')
        ->and($html)->toContain('<details class="account-client">')
        ->and($html)->toContain('<summary class="account-client-summary">')
        ->and($html)->toContain('account-client-indicator')
        ->and($html)->toContain('Email address')
        ->and($html)->not->toContain('verification status')
        ->and($html)->not->toContain('This action is disabled.')
        ->and($html)->toContain('href="https://pulse.test/account/delete"')
        ->and($html)->toContain('Open account deletion in Local Pulse')
        ->and($html)->toContain('href="/authorize?client_id=pulse"')
        ->and($html)->toContain('Back to sign in')
        ->and($html)->toContain('action="/account/display-name?return_to=%2Fauthorize%3Fclient_id%3Dpulse"')
        ->and($html)->not->toContain('href="https://pulse.test"');
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
        ->and($html)->toContain('/js/app.js?v=20260728-client-configuration');
});

it('documents local product logout as the default and central logout as a special case', function (): void {
    $html = $this->renderView('docs_workflow.html', [
        'title' => 'Workflow - Sorkos Login',
        'html_lang' => 'en',
        'active_nav' => 'docs',
        'request_origin' => 'https://auth.example.test',
    ]);

    expect($html)->toContain('Default product logout:')
        ->and($html)->toContain('destroys only its own local product session')
        ->and($html)->toContain('must not call or redirect to the Sorkos Login')
        ->and($html)->toContain('Special case — explicit Sorkos logout:')
        ->and($html)->toContain('Do not use this endpoint for a normal product logout.');
});

it('renders supported branding and settings as dynamic admin configuration entries', function (): void {
    $service = new App\Services\ClientConfigurationService();
    $client = [
        'id' => 7,
        'display_name' => 'Local Pulse',
        'branding_json' => json_encode(['icon' => '/img/pulse.svg']),
        'settings_json' => json_encode(['response_types' => ['code']]),
    ];
    $html = $this->renderView('admin_client_detail.html', [
        'title' => 'Admin Client',
        'html_lang' => 'en',
        'active_nav' => 'admin',
        'client' => $client + [
            'name' => 'local-pulse',
            'client_id' => 'local-pulse',
            'domain' => 'pulse.test',
            'is_active' => 1,
            'is_confidential' => 1,
            'client_secret_hash' => 'hash',
            'created_at' => '2026-07-01 12:00:00',
            'updated_at' => '2026-07-28 12:00:00',
            'default_language' => 'en',
            'allowed_languages' => 'en,de',
            'enabled_providers' => 'email',
        ],
        'redirect_uris' => [],
        'configuration_groups' => $service->groups($client),
    ]);

    expect($html)->toContain('Icon URL')
        ->and($html)->toContain('/img/pulse.svg')
        ->and($html)->toContain('Account deletion URL')
        ->and($html)->toContain('data-configuration-select')
        ->and($html)->toContain('Add entry')
        ->and($html)->not->toContain('Raw Configuration')
        ->and($html)->not->toContain('Images used to identify this client')
        ->and($html)->not->toContain('Optional behavior and project destinations')
        ->and($html)->not->toContain('All supported entries are configured');
});

it('renders the connected user count in the admin clients table', function (): void {
    $html = $this->renderView('admin_clients.html', [
        'title' => 'Admin Clients',
        'html_lang' => 'en',
        'active_nav' => 'admin',
        'clients' => [[
            'id' => 7,
            'display_name' => 'Local Pulse',
            'client_id' => 'local-pulse',
            'domain' => 'pulse.test',
            'enabled_providers' => 'email',
            'user_count' => 3,
            'is_active' => 1,
        ]],
    ]);

    expect($html)->toContain('<th>Users</th>')
        ->and($html)->toContain('<td>3</td>')
        ->and($html)->not->toContain('<th>Redirects</th>');
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
