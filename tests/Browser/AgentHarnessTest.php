<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('users switch a project to Claude Code and pick from Claude models', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    AgentConnection::factory()->for($user)->create(['is_default' => false, 'credential_type' => CredentialType::OAuthToken]);
    $project = Project::factory()->for($user)->create(['agent_session_id' => 'ses_opencode']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->assertSeeIn('@harness-picker', 'OpenCode')
        ->click('@harness-picker')
        ->assertVisible('@harness-menu')
        ->click('@harness-claude_code')
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->assertSeeIn('@model-picker', 'Claude Sonnet 5')
        ->assertSee('Switched to Claude Code')
        ->click('@model-picker')
        ->assertVisible('[aria-label="Anthropic"]')
        ->assertMissing('[aria-label="OpenRouter"]')
        ->click('[data-test="model-claude:claude-opus-5-5"]')
        ->assertSeeIn('@model-picker', 'Claude Opus 5.5')
        ->assertNoJavaScriptErrors();

    $project->refresh();
    expect($project->agent_harness)->toBe(AgentHarness::ClaudeCode)
        ->and($project->agent_model)->toBe('claude-opus-5-5')
        ->and($project->agent_session_id)->toBeNull();
})->group('AGT-007');
