<?php

use App\Enums\CredentialType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('the dev user logs in, connects Claude, and lands on the new-project prompt', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/onboarding/ai')
        ->assertSee('Set up your AI')
        ->assertSee('Connect an AI to continue')
        ->assertDontSee('Claude Code token')
        ->click('@switch-method-claude')
        ->assertSee('Claude Code token');

    $page->fill('#credential-claude', 'sk-ant-oat01-browser-test-token-wxyz')
        ->press('@connect-claude')
        ->assertPathIs('/dashboard')
        ->assertSee('Claude connected.')
        ->assertSee('Dev, what are we working on today?')
        ->assertNoJavaScriptErrors();

    $connection = User::where('email', 'dev@example.com')->sole()->agentConnections()->sole();
    expect($connection->credential_type)->toBe(CredentialType::OAuthToken);
})->group('AUTH-001', 'AI-001');
