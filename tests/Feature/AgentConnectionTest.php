<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('connecting Claude with an API key verifies and stores it', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['data' => []])]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'claude', 'credential' => ' sk-ant-api03-good-key-1234 '])
        ->assertSessionHasNoErrors();

    $connection = $this->user->agentConnections()->sole();

    expect($connection->provider)->toBe(AgentProvider::Claude)
        ->and($connection->credential_type)->toBe(CredentialType::ApiKey)
        ->and($connection->credential)->toBe('sk-ant-api03-good-key-1234')
        ->and($connection->hint)->toBe('1234')
        ->and($connection->is_default)->toBeTrue()
        ->and($connection->verified_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sk-ant-api03-good-key-1234'));
})->group('AI-001');

test('credentials are encrypted at rest', function () {
    $connection = AgentConnection::factory()->for($this->user)->create(['credential' => 'sk-ant-api03-secret']);

    $raw = DB::table('agent_connections')->where('id', $connection->id)->value('credential');

    expect($raw)->not->toContain('sk-ant-api03-secret');
})->group('AI-002');

test('a Claude subscription token is stored unverified without calling the API', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'claude', 'credential' => 'sk-ant-oat01-subscription-token'])
        ->assertSessionHasNoErrors();

    $connection = $this->user->agentConnections()->sole();

    expect($connection->credential_type)->toBe(CredentialType::OAuthToken)
        ->and($connection->verified_at)->toBeNull();
    Http::assertNothingSent();
})->group('AI-001');

test('connecting Codex verifies the key with OpenAI', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'codex', 'credential' => 'sk-openai-key-5678'])
        ->assertSessionHasNoErrors();

    expect($this->user->agentConnections()->sole()->provider)->toBe(AgentProvider::Codex);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-openai-key-5678'));
})->group('AI-001');

test('a rejected key shows an error and is not stored', function () {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => 'nope'], 401)]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'openrouter', 'credential' => 'sk-or-bad-key'])
        ->assertSessionHasErrors(['credential' => 'OpenRouter rejected that key.']);

    expect($this->user->agentConnections()->count())->toBe(0);
})->group('AI-001');

test('an unreachable provider shows a retry error', function () {
    Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('down')]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'codex', 'credential' => 'sk-openai-key'])
        ->assertSessionHasErrors(['credential' => "Couldn't reach Codex to check the key. Try again."]);
})->group('AI-001');

test('an unknown provider is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'skynet', 'credential' => 'whatever-key'])
        ->assertSessionHasErrors('provider');
})->group('AI-001');

test('replacing a key keeps one connection and its default flag', function () {
    Http::fake();
    AgentConnection::factory()->for($this->user)->create(['is_default' => true]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'claude', 'credential' => 'sk-ant-api03-new-key-9999']);

    $connection = $this->user->agentConnections()->sole();
    expect($connection->hint)->toBe('9999')->and($connection->is_default)->toBeTrue();
})->group('AI-002');

test('a second provider is not the default', function () {
    Http::fake();
    AgentConnection::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'codex', 'credential' => 'sk-openai-key']);

    expect($this->user->agentConnections()->where('provider', 'codex')->sole()->is_default)->toBeFalse();
})->group('AI-002');

test('users can choose the default AI', function () {
    $claude = AgentConnection::factory()->for($this->user)->create(['is_default' => true]);
    $codex = AgentConnection::factory()->for($this->user)->provider(AgentProvider::Codex)->create(['is_default' => false]);

    $this->actingAs($this->user)->patch(route('agent-connections.update', $codex));

    expect($codex->fresh()->is_default)->toBeTrue()
        ->and($claude->fresh()->is_default)->toBeFalse();
})->group('AI-002');

test('disconnecting the default promotes another connection', function () {
    $claude = AgentConnection::factory()->for($this->user)->create(['is_default' => true]);
    $codex = AgentConnection::factory()->for($this->user)->provider(AgentProvider::Codex)->create(['is_default' => false]);

    $this->actingAs($this->user)->delete(route('agent-connections.destroy', $claude));

    expect($claude->fresh())->toBeNull()
        ->and($codex->fresh()->is_default)->toBeTrue();
})->group('AI-002');

test('users cannot touch another user\'s connection', function () {
    $theirs = AgentConnection::factory()->create();

    $this->actingAs($this->user)->patch(route('agent-connections.update', $theirs))->assertNotFound();
    $this->actingAs($this->user)->delete(route('agent-connections.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
})->group('AI-002');

test('the settings page lists connections', function () {
    AgentConnection::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->get(route('agent-connections.index'))
        ->assertInertia(fn ($page) => $page->component('settings/ai')->has('connections', 1));
})->group('AI-002');
