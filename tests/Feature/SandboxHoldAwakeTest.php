<?php

use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    config(['sandbox.provider' => 'e2b']);
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);
    $this->withoutDefer();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['provider' => 'e2b', 'external_id' => 'e2b-1']);
});

test('an agent run holds its sandbox awake', function () {
    Queue::fake();
    $message = $this->project->messages()->create(['role' => 'user', 'content' => 'Build it']);

    app()->call([new RunAgentTask($this->project, $message), 'handle']);

    expect($this->provider->held)->toBe(['e2b-1']);
})->group('SBX-014');

test('when the run ends, its sandbox pauses soon if nobody is watching, or after the usual idle time if someone is', function (bool $watched) {
    if ($watched) {
        $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project))->assertOk();
    }

    app(AgentQueue::class)->finished($this->project);

    expect($this->provider->released)->toBe([['e2b-1', ! $watched]]);
})->with(['nobody watching' => false, 'workspace open' => true])->group('SBX-014');

test('leaving the workspace lets its sandbox pause soon, but not while the agent works', function () {
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project), ['left' => true])->assertOk();

    expect($this->provider->released)->toBe([]);

    $this->project->update(['status' => ProjectStatus::Idle]);
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project))->assertOk();
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project), ['left' => true])->assertOk();

    expect($this->provider->released)->toBe([['e2b-1', true]]);
})->group('SBX-014');

test('coming back after leaving puts the usual pause back at once', function () {
    $this->project->update(['status' => ProjectStatus::Idle]);
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project));
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project), ['left' => true]);
    $this->actingAs($this->user)->post(route('projects.sandbox.activity', $this->project));

    expect($this->provider->woken)->toBe(['e2b-1', 'e2b-1']);
})->group('SBX-014');
