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
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Idle]);

    // A preview page that reports an error the way the sandbox's error reporter does.
    $page = "<script>parent.postMessage({onedrop: 'error', error: {type: 'error', message: 'x is not defined', page: '/dashboard'}}, '*')</script>";
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'data:text/html,'.rawurlencode($page)]);
    $this->actingAs($this->user);
});

test('an error in the preview can be sent to the agent to fix', function () {
    visit("/projects/{$this->project->id}")
        ->assertSeeIn('@preview-error', 'This page hit an error')
        ->assertSeeIn('@preview-error', 'Error: x is not defined')
        ->click('@preview-error-fix')
        ->assertMissing('@preview-error')
        ->assertSee('The preview shows this error')
        ->assertNoJavaScriptErrors();

    expect($this->project->messages()->first()->content)
        ->toContain('Error on /dashboard: x is not defined')
        ->toContain('/workspace/.onedrop/errors.log');
})->group('ERR-001');

test('the error bar can be dismissed', function () {
    visit("/projects/{$this->project->id}")
        ->assertVisible('@preview-error')
        ->click('@preview-error-dismiss')
        ->assertMissing('@preview-error')
        ->assertNoJavaScriptErrors();

    expect($this->project->messages()->count())->toBe(0);
})->group('ERR-001');

test('autofix can be turned off and on from the chat controls', function () {
    visit("/projects/{$this->project->id}")
        ->assertAttribute('@composer-autofix', 'aria-pressed', 'true')
        ->click('@composer-autofix')
        ->assertAttribute('@composer-autofix', 'aria-pressed', 'false')
        ->assertNoJavaScriptErrors();

    expect($this->project->fresh()->autofix)->toBeFalse();
})->group('ERR-001');
