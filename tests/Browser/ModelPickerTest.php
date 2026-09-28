<?php

use App\Enums\AgentProvider;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('users pick a model and reasoning level for a project from the chat composer', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->create(['is_default' => true]);
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => false]);
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertSeeIn('@model-picker', 'Claude Sonnet 5')
        ->assertSeeIn('@reasoning-picker', 'Default')
        ->click('@model-picker')
        ->assertVisible('@model-menu')
        ->assertSee('Claude Opus 5.5')
        ->click('[aria-label="OpenRouter"]')
        ->fill('@model-search', 'kimi')
        ->assertSee('MoonshotAI: Kimi K3')
        ->assertDontSee('Anthropic: Claude Sonnet 5')
        ->click('[data-test="star-openrouter:moonshotai/kimi-k3"]')
        ->fill('@model-search', 'sonnet')
        ->click('[data-test="model-openrouter:anthropic/claude-sonnet-5"]')
        ->assertSeeIn('@model-picker', 'Anthropic: Claude Sonnet 5')
        ->click('@reasoning-picker')
        ->click('@reasoning-max')
        ->assertSeeIn('@reasoning-picker', 'Max')
        ->click('@model-picker')
        ->assertSeeIn('@recent-models', 'Anthropic: Claude Sonnet 5')
        ->assertNoJavaScriptErrors();

    $project->refresh();
    expect($project->agent_provider)->toBe(AgentProvider::OpenRouter)
        ->and($project->agent_model)->toBe('anthropic/claude-sonnet-5')
        ->and($project->agent_variant)->toBe('max')
        ->and($user->fresh()->favorite_models)->toBe(['openrouter:moonshotai/kimi-k3']);
})->group('AGT-002');
