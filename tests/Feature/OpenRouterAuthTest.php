<?php

use App\Enums\AgentProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('signing in with OpenRouter redirects with a PKCE challenge', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('openrouter.redirect'));

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    $verifier = session('openrouter.verifier');
    $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    expect($location)->toStartWith('https://openrouter.ai/auth?')
        ->and($query['callback_url'])->toBe(route('openrouter.callback'))
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe($expected);
})->group('AI-001');

test('the OpenRouter callback exchanges the code for a key', function () {
    Http::fake([
        'openrouter.ai/api/v1/auth/keys' => Http::response(['key' => 'sk-or-v1-from-oauth-abcd']),
        'openrouter.ai/api/v1/key' => Http::response(['data' => []]),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['openrouter.verifier' => 'the-verifier', 'openrouter.return_to' => route('onboarding.ai')])
        ->get(route('openrouter.callback', ['code' => 'auth-code']))
        ->assertRedirect(route('dashboard'));

    $connection = $user->agentConnections()->sole();
    expect($connection->provider)->toBe(AgentProvider::OpenRouter)
        ->and($connection->hint)->toBe('abcd');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/auth/keys'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'the-verifier');
})->group('AI-001');

test('the OpenRouter callback returns to settings when started there', function () {
    Http::fake([
        'openrouter.ai/api/v1/auth/keys' => Http::response(['key' => 'sk-or-v1-from-oauth-abcd']),
        'openrouter.ai/api/v1/key' => Http::response(['data' => []]),
    ]);

    $this->actingAs(User::factory()->create())
        ->withSession(['openrouter.verifier' => 'the-verifier', 'openrouter.return_to' => route('agent-connections.index')])
        ->get(route('openrouter.callback', ['code' => 'auth-code']))
        ->assertRedirect(route('agent-connections.index'));
})->group('AI-002');

test('the OpenRouter callback fails safely without a pending sign-in', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('openrouter.callback', ['code' => 'auth-code']))
        ->assertRedirect(route('onboarding.ai'));

    expect($user->agentConnections()->count())->toBe(0);
    Http::assertNothingSent();
})->group('AI-001');

test('a failed OpenRouter exchange stores nothing', function () {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => 'bad code'], 400)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['openrouter.verifier' => 'the-verifier'])
        ->get(route('openrouter.callback', ['code' => 'bad']))
        ->assertRedirect(route('onboarding.ai'));

    expect($user->agentConnections()->count())->toBe(0);
})->group('AI-001');
