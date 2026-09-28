<?php

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
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->project->messages()->create(['role' => 'user', 'content' => 'build a todo app']);
    $this->project->messages()->create(['role' => 'assistant', 'content' => 'Done.']);
    $this->project->messages()->create(['role' => 'user', 'content' => "add a footer\nwith links"]);
    $this->actingAs($this->user);
});

test('up and down cycle through earlier prompts', function () {
    visit("/projects/{$this->project->id}")
        ->keys('#composer-content', 'ArrowUp')
        ->assertValue('#composer-content', "add a footer\nwith links")
        ->keys('#composer-content', 'ArrowUp')
        ->keys('#composer-content', 'ArrowUp')
        ->assertValue('#composer-content', 'build a todo app')
        ->keys('#composer-content', 'ArrowUp')
        ->assertValue('#composer-content', 'build a todo app')
        ->keys('#composer-content', 'ArrowDown')
        ->assertValue('#composer-content', "add a footer\nwith links")
        ->keys('#composer-content', 'ArrowDown')
        ->assertValue('#composer-content', '')
        ->assertNoJavaScriptErrors();
})->group('AGT-004');

test('typing is never replaced by a recalled prompt', function () {
    visit("/projects/{$this->project->id}")
        ->fill('#composer-content', 'my draft')
        ->keys('#composer-content', 'ArrowUp')
        ->assertValue('#composer-content', 'my draft')
        ->assertNoJavaScriptErrors();
})->group('AGT-004');
