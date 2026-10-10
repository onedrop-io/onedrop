<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Jobs\WatchAgentRun;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->project = Project::factory()->for(User::factory())->create(['status' => ProjectStatus::Working]);
    Sandbox::factory()->for($this->project)->create(['provider' => 'docker', 'external_id' => 'ctr-1']);
    $this->message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Build it']);
});

test('a run whose forwarder is gone without saying so is ended, and the chat says so', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '');

    app()->call([new WatchAgentRun($this->project, $this->message), 'handle']);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($this->project->messages()->get()->last()->content)->toBe('The agent stopped without finishing its turn')
        ->and($this->provider->executed[0]['command'])->toBe(['bash', '-c', 'kill -0 "$(cat "$1" 2>/dev/null)" 2>/dev/null', 'watch-agent', '/tmp/onedrop-agent.pid']);
})->group('AGT-001');

test('a run still going is checked again later; one that is over, or a later message\'s, is left alone', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    app()->call([new WatchAgentRun($this->project, $this->message), 'handle']);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Working);
    Queue::assertPushed(WatchAgentRun::class, fn (WatchAgentRun $job) => $job->message->is($this->message) && $job->unanswered === 0);

    Queue::fake();
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'And this']);
    app()->call([new WatchAgentRun($this->project, $this->message), 'handle']);
    $this->project->update(['status' => ProjectStatus::Idle]);
    app()->call([new WatchAgentRun($this->project, $this->message), 'handle']);

    expect($this->provider->executed)->toHaveCount(1);
    Queue::assertNothingPushed();
})->group('AGT-001');

test('a sandbox that doesn\'t answer is asked again a few times before the run is given up on', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $this->provider->execUsing = fn () => throw new SandboxException('No answer');

    app()->call([new WatchAgentRun($this->project, $this->message), 'handle']);
    Queue::assertPushed(WatchAgentRun::class, fn (WatchAgentRun $job) => $job->unanswered === 1);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Working);

    app()->call([new WatchAgentRun($this->project, $this->message, WatchAgentRun::UNANSWERED - 1), 'handle']);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
})->group('AGT-001');

test('starting a run checks on it 15 minutes later, except on a sync queue, which can\'t wait', function () {
    Queue::fake([WatchAgentRun::class]);

    RunAgentTask::dispatchSync($this->project, $this->message);
    Queue::assertNotPushed(WatchAgentRun::class);

    config(['queue.default' => 'database']);
    RunAgentTask::dispatchSync($this->project, $this->message);
    Queue::assertPushed(WatchAgentRun::class, fn (WatchAgentRun $job) => $job->delay->diffInMinutes(now(), true) >= 14);
})->group('AGT-001');
