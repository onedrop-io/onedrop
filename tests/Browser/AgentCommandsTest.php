<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Skill;
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
    $this->project->skills()->attach(Skill::factory()->for($this->user)->create(['name' => 'brand-voice', 'description' => 'Write in our voice']));
    $this->actingAs($this->user);
});

test('typing "/" lists the agent\'s commands to pick from', function () {
    visit("/projects/{$this->project->id}")
        ->fill('#composer-content', '/')
        ->assertVisible('[data-test="command-menu"]')
        ->assertSeeIn('[data-test="command-menu"]', '/review')
        ->assertSeeIn('[data-test="command-menu"]', 'Write in our voice')
        ->fill('#composer-content', '/bra')
        ->assertDontSeeIn('[data-test="command-menu"]', '/review')
        ->keys('#composer-content', 'Tab')
        ->assertValue('#composer-content', '/brand-voice ')
        ->assertMissing('[data-test="command-menu"]')
        ->assertNoJavaScriptErrors();
})->group('AGT-016');

test('Escape closes the menu, and text that isn\'t a command never opens it', function () {
    visit("/projects/{$this->project->id}")
        ->fill('#composer-content', '/re')
        ->assertVisible('[data-test="command-menu"]')
        ->keys('#composer-content', 'Escape')
        ->assertMissing('[data-test="command-menu"]')
        ->fill('#composer-content', 'fix /review page')
        ->assertMissing('[data-test="command-menu"]')
        ->assertNoJavaScriptErrors();
})->group('AGT-016');
