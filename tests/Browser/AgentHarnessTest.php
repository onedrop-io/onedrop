<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('users switch a project to Claude Code and pick from Claude models', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    AgentConnection::factory()->for($user)->claudeLogin()->create(['is_default' => false]);
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

test('users sign in to Claude from the chat in Claude Code\'s own sign-in in the Shell tab', function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => new ExecResult(1, json_encode(['loggedIn' => false, 'authMethod' => 'none']));
    app()->instance(SandboxProvider::class, $provider);
    $user = User::factory()->create();
    AgentConnection::factory()->for($user)->claudeLogin()->create();
    $project = Project::factory()->for($user)->create(['agent_harness' => AgentHarness::ClaudeCode]);
    Sandbox::factory()->for($project)->create(['status' => SandboxStatus::Running, 'preview_url' => null, 'shell_url' => 'http://127.0.0.1:7681']);
    $this->actingAs($user);

    $page = visit("/projects/{$project->id}")
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->click('@claude-sign-in')
        ->assertVisible('@shell-frame')
        ->assertAttribute('@shell-frame', 'src', 'http://127.0.0.1:7681/?arg=claude-login')
        ->assertNoJavaScriptErrors();

    // Once Claude Code reports a sign-in, the chat shows who it's signed in as.
    $provider->execUsing = fn (array $command) => new ExecResult(0, json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com']));

    $page->wait(6)->assertSeeIn('@claude-login-status', 'Claude Code is signed in as dev@example.com');
})->group('AI-005');
