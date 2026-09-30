<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();

    $this->runner = new class extends FakeAgentRunner
    {
        public int $stops = 0;

        public function stop(Conversation $conversation): void
        {
            $this->stops++;
        }
    };
    app()->instance(AgentRunner::class, $this->runner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
});

function sendMessage(Project $project, string $content, ?string $mode = null): TestResponse
{
    return test()->post(route('projects.messages.store', $project), array_filter(['content' => $content, 'mode' => $mode]));
}

test('messages sent while the agent works are queued, not run', function () {
    $this->actingAs($this->user);

    sendMessage($this->project, 'add dark mode')->assertRedirect(route('projects.show', $this->project));

    expect($this->project->queuedMessages()->pluck('content')->all())->toBe(['add dark mode'])
        ->and($this->project->messages()->count())->toBe(0);
    Queue::assertNothingPushed();

    $this->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->has('queued', 1)->where('queued.0.content', 'add dark mode')->has('messages', 0));
})->group('AGT-003');

test('when a run finishes the next queued message starts, in order', function () {
    $this->actingAs($this->user);
    sendMessage($this->project, 'first');
    sendMessage($this->project, 'second');

    app(AgentQueue::class)->finished($this->project->fresh());

    $this->project->refresh();
    expect($this->project->status)->toBe(ProjectStatus::Working)
        ->and($this->project->messages()->pluck('content')->all())->toBe(['first'])
        ->and($this->project->queuedMessages()->pluck('content')->all())->toBe(['second']);
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->content === 'first');
})->group('AGT-003');

test('when a run finishes with nothing queued the agent goes idle', function () {
    app(AgentQueue::class)->finished($this->project);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
    Queue::assertNothingPushed();
})->group('AGT-003');

test('send now stops the current run and starts the message, keeping the queue', function () {
    $this->actingAs($this->user);
    sendMessage($this->project, 'queued one');

    sendMessage($this->project, 'do this instead', 'now');

    $this->project->refresh();
    expect($this->runner->stops)->toBe(1)
        ->and($this->project->status)->toBe(ProjectStatus::Working)
        ->and($this->project->messages()->pluck('content')->all())->toBe(['Stopped', 'do this instead'])
        ->and($this->project->queuedMessages()->pluck('content')->all())->toBe(['queued one']);
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->content === 'do this instead');
})->group('AGT-003');

test('stop ends the run and hands queued messages back as a draft', function () {
    $this->actingAs($this->user);
    sendMessage($this->project, 'one');
    sendMessage($this->project, 'two');

    $this->post(route('projects.agent.stop', $this->project))
        ->assertRedirect(route('projects.show', $this->project))
        ->assertInertiaFlash('draft', "one\n\ntwo");

    $this->project->refresh();
    expect($this->runner->stops)->toBe(1)
        ->and($this->project->status)->toBe(ProjectStatus::Idle)
        ->and($this->project->queuedMessages()->count())->toBe(0)
        ->and($this->project->messages()->sole()->only('role', 'content'))->toBe(['role' => MessageRole::Activity, 'content' => 'Stopped']);
})->group('AGT-003');

test('stopping an idle project does nothing', function () {
    $this->project->update(['status' => ProjectStatus::Idle]);

    $this->actingAs($this->user)->post(route('projects.agent.stop', $this->project));

    expect($this->runner->stops)->toBe(0)
        ->and($this->project->messages()->count())->toBe(0);
})->group('AGT-003');

test('queued messages can be removed before they run', function () {
    $this->actingAs($this->user);
    sendMessage($this->project, 'never mind');
    $queued = $this->project->queuedMessages()->sole();

    $this->delete(route('projects.messages.destroy', [$this->project, $queued]))->assertRedirect(route('projects.show', $this->project));

    expect($this->project->queuedMessages()->count())->toBe(0);
})->group('AGT-003');

test('sent messages cannot be removed, and only by the owner', function () {
    $this->project->update(['status' => ProjectStatus::Idle]);
    $sent = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'hi']);
    $queued = $this->project->queuedMessages()->create(['role' => MessageRole::User, 'content' => 'later', 'queued' => true]);

    $this->actingAs($this->user)->delete(route('projects.messages.destroy', [$this->project, $sent]))->assertNotFound();
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->delete(route('projects.messages.destroy', [$this->project, $queued]))
        ->assertForbidden();
    $this->actingAs($this->user)->post(route('projects.agent.stop', $this->project->fresh()));
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->post(route('projects.agent.stop', $this->project))
        ->assertForbidden();

    expect($sent->fresh())->not->toBeNull();
})->group('AGT-003');

test('stopping a real run kills it in the sandbox and ignores its later events', function () {
    $provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $provider);
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $oldToken = $sandbox->issueEventsToken();

    app(OpenCodeRunner::class)->stop($this->project);

    expect($provider->executed[0]['command'])->toBe(['/opt/onedrop/stop-agent', 'main'])
        ->and($sandbox->fresh()->acceptsEventsToken($oldToken))->toBeFalse();

    $this->withToken($oldToken)
        ->postJson(route('sandbox-events.store', $sandbox), ['events' => [['type' => 'text', 'part' => ['text' => 'late output']]]])
        ->assertUnauthorized();
})->group('AGT-003');
