<?php

use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('while the agent works, messages queue and stop hands them back', function () {
    visit("/projects/{$this->project->id}")
        ->assertVisible('@composer-stop')
        ->fill('#composer-content', 'add a dark mode')
        ->assertVisible('@composer-send-now')
        ->assertAttribute('@composer-send', 'aria-label', 'Queue')
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@message-queued', 'add a dark mode')
        ->fill('#composer-content', 'and a footer')
        ->keys('#composer-content', 'Enter')
        ->assertSee('and a footer')
        ->click('@composer-stop')
        ->assertSee('Stopped')
        ->assertMissing('@message-queued')
        ->assertValue('#composer-content', "add a dark mode\n\nand a footer")
        ->assertNoJavaScriptErrors();

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($this->project->queuedMessages()->count())->toBe(0);
})->group('AGT-003');

test('a queued message can be removed', function () {
    $this->project->queuedMessages()->create(['role' => 'user', 'content' => 'never mind', 'queued' => true]);

    visit("/projects/{$this->project->id}")
        ->assertSeeIn('@message-queued', 'never mind')
        ->click('@remove-queued')
        ->assertMissing('@message-queued')
        ->assertNoJavaScriptErrors();

    expect($this->project->queuedMessages()->count())->toBe(0);
})->group('AGT-003');
