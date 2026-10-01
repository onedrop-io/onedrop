<?php

use App\Models\AgentConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('users without an AI are sent to onboarding', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.ai'));
})->group('AI-001');

test('users with an AI can reach the dashboard', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->followingRedirects()->get(route('dashboard'))
        ->assertOk();
})->group('AI-001');

test('onboarding lists connections without credentials', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($user)
        ->get(route('onboarding.ai'))
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/ai')
            ->has('connections', 1)
            ->where('connections.0.provider', 'claude')
            ->missing('connections.0.credential'))
        ->assertDontSee($user->agentConnections()->first()->credential);
})->group('AI-002');

test('connecting during onboarding goes straight to the new-project prompt', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->from(route('onboarding.ai'))
        ->post(route('agent-connections.store'), ['provider' => 'claude', 'credential' => 'sk-ant-api03-key', 'onboarding' => '1'])
        ->assertRedirect(route('dashboard'));
})->group('AI-001');

test('connecting from settings stays on settings', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->from(route('agent-connections.index'))
        ->post(route('agent-connections.store'), ['provider' => 'claude', 'credential' => 'sk-ant-api03-key'])
        ->assertRedirect(route('agent-connections.index'));
})->group('AI-002');
