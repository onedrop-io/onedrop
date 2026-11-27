<?php

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.task_copies' => false]);
    Queue::fake();
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);

    $this->user = User::factory()->create();
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['is_default' => true]);
    AgentConnection::factory()->for($this->user)->create(['is_default' => false]); // Claude API key
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test("users pick a task's own agent and model on its page, and Main keeps its own", function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Dark mode']);

    visit("/projects/{$this->project->id}/tasks/{$task->id}")
        ->assertSeeIn('@harness-picker', 'OpenCode')
        ->click('@harness-picker')
        ->click('@harness-claude_code')
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->assertSee('Switched to Claude Code')
        ->click('@model-picker')
        ->click('[data-test="model-claude:claude-opus-5-5"]')
        ->assertSeeIn('@model-picker', 'Claude Opus 5.5')
        ->assertPathIs("/projects/{$this->project->id}/tasks/{$task->id}")
        ->assertNoJavaScriptErrors();

    $task->refresh();
    expect([$task->agent_harness, $task->agent_model])->toBe([AgentHarness::ClaudeCode, 'claude-opus-5-5'])
        ->and($this->project->fresh()->agent_harness)->toBeNull();

    visit("/projects/{$this->project->id}")
        ->assertSeeIn('@harness-picker', 'OpenCode')
        ->assertNoJavaScriptErrors();
})->group('TASK-007');

test("users pick a new task's agent before its first message, without changing Main's", function () {
    visit("/projects/{$this->project->id}/tasks/new")
        ->click('@harness-picker')
        ->click('@harness-claude_code')
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->fill('#composer-content', 'Add a footer')
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@task-title', 'Add a footer')
        ->assertSeeIn('@harness-picker', 'Claude Code')
        ->assertNoJavaScriptErrors();

    $task = Task::sole();
    expect($task->agent_harness)->toBe(AgentHarness::ClaudeCode)
        ->and($task->agent_provider)->toBe(AgentProvider::Claude)
        ->and($this->project->fresh()->agent_harness)->toBeNull();
})->group('TASK-007');
