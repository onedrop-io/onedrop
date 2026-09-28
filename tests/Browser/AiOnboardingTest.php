<?php

use App\Enums\CredentialType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;

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

test('the dev user signs in with ChatGPT and lands on the new-project prompt', function () {
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response(['device_auth_id' => 'dev-auth-1', 'user_code' => 'WXYZ-9876', 'interval' => '1']),
        'auth.openai.com/api/accounts/deviceauth/token' => Http::sequence()
            ->push([], 403)
            ->push(['authorization_code' => 'auth-code', 'code_verifier' => 'the-verifier']),
        'auth.openai.com/oauth/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 864000]),
    ]);

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/onboarding/ai')
        ->assertSee('Uses your ChatGPT Plus or Pro plan')
        ->click('@signin-codex')
        ->assertSee('WXYZ-9876')
        ->assertSee('auth.openai.com/codex/device');

    $page->assertPathIs('/dashboard')
        ->assertSee('ChatGPT connected.')
        ->assertSee('Dev, what are we working on today?')
        ->assertNoJavaScriptErrors();

    $connection = User::where('email', 'dev@example.com')->sole()->agentConnections()->sole();
    expect($connection->credential_type)->toBe(CredentialType::ChatGpt);
})->group('AI-003');

test('the Codex card still takes a pasted OpenAI API key', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/onboarding/ai')
        ->click('@switch-method-codex')
        ->fill('#credential-codex', 'sk-proj-browser-test-key-abcd')
        ->press('@connect-codex')
        ->assertPathIs('/dashboard')
        ->assertSee('Codex connected.')
        ->assertNoJavaScriptErrors();
})->group('AI-003');
