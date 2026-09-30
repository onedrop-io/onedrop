<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
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

test('first read returns the recent log and the current size', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, "1234\n  VITE ready in 300 ms\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.logs.index', $this->project))
        ->assertOk()
        ->assertExactJson(['content' => "  VITE ready in 300 ms\n", 'offset' => 1234]);

    expect($this->provider->executed[0]['command'][0])->toBe('sh');
})->group('TAB-001');

test('later reads return only new output after the offset', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, "page reload src/App.tsx\n");

    $this->actingAs($this->user)
        ->getJson(route('projects.logs.index', [$this->project, 'offset' => 1234]))
        ->assertOk()
        ->assertExactJson(['content' => "page reload src/App.tsx\n", 'offset' => 1234 + 24]);

    expect($this->provider->executed[0]['command'])->toBe(['tail', '--bytes', '+1235', '/tmp/onedrop-server.log']);
})->group('TAB-001');

test('a burst of output is trimmed to the latest part but the offset stays exact', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, str_repeat('x', 70_000));

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.logs.index', [$this->project, 'offset' => 0]))
        ->assertOk();

    expect(strlen($response->json('content')))->toBe(64_000)
        ->and($response->json('offset'))->toBe(70_000);
})->group('TAB-001');

test('the offset must be a non-negative number', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.logs.index', [$this->project, 'offset' => '-1; rm -rf /']))
        ->assertUnprocessable();

    expect($this->provider->executed)->toBe([]);
})->group('TAB-001');

test('logs are private to the project owner', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->getJson(route('projects.logs.index', $this->project))
        ->assertForbidden();
})->group('TAB-001');

test('a stopped sandbox has no logs', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route('projects.logs.index', $this->project))
        ->assertStatus(409);
})->group('TAB-001');
