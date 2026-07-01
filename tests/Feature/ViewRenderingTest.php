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
        ->and($html)->toContain('Google and Discord are visible only as configured placeholders for now.');
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
