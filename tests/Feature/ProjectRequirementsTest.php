<?php

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\HarnessRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('shows the requirements the agent keeps in .onedrop/REQ.md', function () {
    $this->provider->execUsing = fn (array $command) => new ExecResult(0, $command[0] === 'test' ? '' : "# Requirements\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.requirements.show', $this->project))
        ->assertOk()
        ->assertExactJson(['path' => '.onedrop/REQ.md', 'content' => "# Requirements\n", 'notice' => null]);

    expect($this->provider->executed[0]['command'])->toBe(['test', '-f', '/workspace/.onedrop/REQ.md'])
        ->and($this->provider->executed[1]['command'])->toBe(['head', '--bytes', '200001', '--', '/workspace/.onedrop/REQ.md']);
})->group('REQ-001');

test('no requirements yet is not an error', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '');

    $this->actingAs($this->user)
        ->getJson(route('projects.requirements.show', $this->project))
        ->assertOk()
        ->assertExactJson(['path' => '.onedrop/REQ.md', 'content' => null, 'notice' => null]);

    expect($this->provider->executed)->toHaveCount(1);
})->group('REQ-001');

test('a stopped sandbox says why the requirements are unavailable', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route('projects.requirements.show', $this->project))
        ->assertStatus(409)
        ->assertJson(['message' => "The project's sandbox isn't running."]);
})->group('REQ-001');

test('requirements tracking is on for new projects and can be turned off and on', function () {
    expect($this->project->fresh()->track_requirements)->toBeTrue();

    $this->actingAs($this->user)
        ->patch(route('projects.requirements.update', $this->project), ['track_requirements' => false])
        ->assertRedirect();

    expect($this->project->fresh()->track_requirements)->toBeFalse();

    $this->actingAs($this->user)
        ->patch(route('projects.requirements.update', $this->project), ['track_requirements' => true]);

    expect($this->project->fresh()->track_requirements)->toBeTrue();
})->group('REQ-002');

test('other users cannot read the requirements or change tracking', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->getJson(route('projects.requirements.show', $this->project))->assertForbidden();
    $this->actingAs($other)->patch(route('projects.requirements.update', $this->project), ['track_requirements' => false])->assertForbidden();

    expect($this->provider->executed)->toBe([])
        ->and($this->project->fresh()->track_requirements)->toBeTrue();
})->group('REQ-002');

test('the agent is told whether to keep the requirements', function (bool $tracking, string $flag) {
    $this->project->update(['track_requirements' => $tracking]);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'add a contact form']);

    app(HarnessRunner::class)->start($this->project, $message);

    $forwarder = collect($this->provider->executed)->firstWhere('command', ['node', '/opt/onedrop/forwarder.mjs']);

    expect($forwarder['env']['APP_REQUIREMENTS'])->toBe($flag);
})->with([
    'on' => [true, '1'],
    'off' => [false, ''],
])->group('REQ-002');
