<?php

use App\Enums\CredentialType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('the dev user logs in, chooses their Claude subscription, and lands on the new-project prompt', function () {
    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/onboarding/ai')
        ->assertSee('Which AI do you use?')
        ->assertSee('Claude Pro or Max plan')
        ->assertMissing('@onboarding-continue')
        ->assertDontSee('OneDrop never sees your Claude login')
        ->click('@choose-claude')
        ->assertSee('Connect Claude')
        ->assertSee('OneDrop never sees your Claude login')
        ->assertSee('Use an Anthropic API key instead');

    $page->press('@use-claude-subscription')
        ->assertPathIs('/dashboard')
        ->assertSee('Claude subscription added.')
        ->assertSee('Dev, what are we working on today?')
        ->assertNoJavaScriptErrors();

    $connection = User::where('email', 'dev@example.com')->sole()->agentConnections()->sole();
    expect($connection->credential_type)->toBe(CredentialType::ClaudeLogin)
        ->and($connection->credential)->toBe('');
})->group('AUTH-001', 'AI-001', 'AI-005');

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
        ->click('@choose-codex')
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

test('the dev user can go back and pick a different AI, or paste a Claude API key', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['data' => []])]);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->click('@choose-gemini')
        ->assertSee('Connect Gemini')
        ->click('@choose-another-ai')
        ->assertSee('Which AI do you use?')
        ->click('@choose-claude')
        ->click('@switch-method-claude')
        ->fill('#credential-claude', 'sk-ant-api03-browser-test-key-abcd')
        ->press('@connect-claude')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
})->group('AI-001');

test('the dev user picks Ollama and connects it with an Ollama Cloud key', function () {
    Http::fake(['ollama.com/api/me' => Http::response(['name' => 'dev'])]);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertSee('Cloud key or your server')
        ->click('@choose-ollama')
        ->fill('#credential-ollama', 'ollama-browser-test-key')
        ->press('@connect-ollama')
        ->assertPathIs('/dashboard')
        ->assertSee('Ollama connected.')
        ->assertNoJavaScriptErrors();
})->group('AI-001', 'AI-006');

test('the dev user connects their own Ollama server by URL', function () {
    Http::fake(['8.8.8.8:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b']]])]);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->click('@choose-ollama')
        ->click('@switch-method-ollama')
        ->assertSee('it needs a public address')
        ->fill('#ollama-url', 'http://8.8.8.8:11434')
        ->fill('#ollama-key', 'server-key-1234')
        ->press('@connect-ollama-server')
        ->assertPathIs('/dashboard')
        ->assertSee('Ollama server connected.')
        ->assertNoJavaScriptErrors();
})->group('AI-001', 'AI-006');

test('the Codex card still takes a pasted OpenAI API key', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);

    visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs('/onboarding/ai')
        ->click('@choose-codex')
        ->click('@switch-method-codex')
        ->fill('#credential-codex', 'sk-proj-browser-test-key-abcd')
        ->press('@connect-codex')
        ->assertPathIs('/dashboard')
        ->assertSee('Codex connected.')
        ->assertNoJavaScriptErrors();
})->group('AI-003');
