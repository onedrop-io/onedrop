<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\TaskStage;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Tasks sharing Main's sandbox (TASK-001); their own copies are covered in TaskCopyTest.
    config(['sandbox.task_copies' => false]);
    Queue::fake();

    $this->runner = new class extends FakeAgentRunner
    {
        /** @var list<string> */
        public array $stopped = [];

        public function stop(Conversation $conversation): void
        {
            $this->stopped[] = $conversation->runKey();
        }
    };
    app()->instance(AgentRunner::class, $this->runner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('a first message starts a task with a fresh agent, separate from the main chat', function () {
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    $response = $this->post(route('projects.tasks.store', $this->project), ['content' => 'Add a dark mode toggle to the header']);

    $task = Task::sole();
    $response->assertRedirect(route('projects.tasks.show', [$this->project, $task]));

    expect($task->title)->toBe('Add a dark mode toggle to the header')
        ->and($task->stage)->toBe(TaskStage::InProgress)
        ->and($task->status)->toBe(ProjectStatus::Working)
        ->and($task->agent_session_id)->toBeNull()
        ->and($task->messages()->pluck('content')->all())->toBe(['Add a dark mode toggle to the header'])
        ->and($this->project->messages()->pluck('content')->all())->toBe(['build a timer'])
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->task_id === $task->id);

    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page->component('projects/show')
            ->where('task.id', $task->id)
            ->where('task.status', 'working')
            ->has('messages', 1)
            ->where('messages.0.content', 'Add a dark mode toggle to the header'));
})->group('TASK-001');

test('tasks run while the main chat and other tasks are working', function () {
    $this->project->update(['status' => ProjectStatus::Working]);
    $busy = Task::factory()->for($this->project)->working()->create();
    $idle = Task::factory()->for($this->project)->create();

    $this->post(route('projects.tasks.messages.store', [$this->project, $idle]), ['content' => 'write the tests']);

    expect($idle->fresh()->status)->toBe(ProjectStatus::Working)
        ->and($idle->messages()->pluck('content')->all())->toBe(['write the tests'])
        ->and($idle->queuedMessages()->count())->toBe(0)
        ->and($busy->fresh()->status)->toBe(ProjectStatus::Working)
        ->and($this->runner->stopped)->toBe([]);
    Queue::assertPushed(RunAgentTask::class, 1);
})->group('TASK-001');

test('messages to a working task wait in its own queue', function () {
    $task = Task::factory()->for($this->project)->working()->create();

    $this->post(route('projects.tasks.messages.store', [$this->project, $task]), ['content' => 'also add a footer'])
        ->assertRedirect(route('projects.tasks.show', [$this->project, $task]));

    expect($task->queuedMessages()->pluck('content')->all())->toBe(['also add a footer'])
        ->and($this->project->queuedMessages()->count())->toBe(0);
    Queue::assertNothingPushed();

    app(AgentQueue::class)->finished($task->fresh());

    expect($task->messages()->pluck('content')->all())->toBe(['also add a footer'])
        ->and($task->queuedMessages()->count())->toBe(0);
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->task_id === $task->id);
})->group('TASK-001');

test('a queued task message can be removed, but not through another task', function () {
    $task = Task::factory()->for($this->project)->working()->create();
    $other = Task::factory()->for($this->project)->create();
    $queued = $task->queuedMessages()->create(['role' => MessageRole::User, 'content' => 'later', 'queued' => true]);

    $this->delete(route('projects.tasks.messages.destroy', [$this->project, $other, $queued]))->assertNotFound();
    $this->delete(route('projects.tasks.messages.destroy', [$this->project, $task, $queued]))->assertRedirect();

    expect(Message::find($queued->id))->toBeNull();
})->group('TASK-001');

test('stopping a task stops only its run and hands back its queue', function () {
    $this->project->update(['status' => ProjectStatus::Working]);
    $task = Task::factory()->for($this->project)->working()->create();
    $task->queuedMessages()->create(['role' => MessageRole::User, 'content' => 'next idea', 'queued' => true]);

    $this->post(route('projects.tasks.stop', [$this->project, $task]))
        ->assertRedirect(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertiaFlash('draft', 'next idea');

    expect($this->runner->stopped)->toBe(["task-{$task->id}"])
        ->and($task->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($task->queuedMessages()->count())->toBe(0)
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Working);
})->group('TASK-001');

test("stopping a task's real run leaves the sandbox's other runs and events alone", function () {
    $provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $provider);
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $task = Task::factory()->for($this->project)->working()->create();
    $mainToken = $sandbox->issueEventsToken();
    $taskToken = $task->issueEventsToken();

    app(OpenCodeRunner::class)->stop($task);

    expect($provider->executed[0]['command'])->toBe(['/opt/zap/stop-agent', "task-{$task->id}"])
        ->and($task->fresh()->acceptsEventsToken($taskToken))->toBeFalse()
        ->and($sandbox->fresh()->acceptsEventsToken($mainToken))->toBeTrue();
})->group('TASK-001');

test("a task's run reports to its own chat with its own token", function () {
    $sandbox = Sandbox::factory()->for($this->project)->create();
    $task = Task::factory()->for($this->project)->working()->create();
    $token = $task->issueEventsToken();
    $url = route('sandbox-events.tasks.store', [$sandbox, $task]);

    $this->withToken($sandbox->issueEventsToken())->postJson($url, ['events' => [['type' => 'zap.start']]])->assertUnauthorized();
    $this->withToken($token)->postJson(route('sandbox-events.tasks.store', [Sandbox::factory()->create(), $task]), ['events' => [['type' => 'zap.start']]])->assertNotFound();

    $this->withToken($token)->postJson($url, ['events' => [
        ['type' => 'text', 'sessionID' => 'ses_task', 'part' => ['type' => 'text', 'text' => 'Added the toggle.']],
        ['type' => 'zap.exit', 'code' => 0, 'stderr' => ''],
    ]])->assertOk();

    $task->refresh();
    expect($task->messages()->pluck('content')->all())->toBe(['Added the toggle.'])
        ->and($task->agent_session_id)->toBe('ses_task')
        ->and($task->status)->toBe(ProjectStatus::Idle)
        ->and($task->stage)->toBe(TaskStage::Review)
        ->and($this->project->messages()->count())->toBe(0)
        ->and($this->project->fresh()->agent_session_id)->toBeNull();
})->group('TASK-001', 'TASK-002');

test('the sidebar lists open tasks under their project, and a working task makes the project show as working', function () {
    Task::factory()->for($this->project)->working()->create(['title' => 'Dark mode']);
    Task::factory()->for($this->project)->create(['title' => 'Old one', 'stage' => TaskStage::Done]);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('sidebarProjects.recent.0.working', true)
            ->has('sidebarProjects.recent.0.tasks', 1)
            ->where('sidebarProjects.recent.0.tasks.0.title', 'Dark mode')
            ->where('sidebarProjects.recent.0.tasks.0.working', true)
            ->where('openProject', null));
})->group('TASK-001');

test('an opened project shows in the sidebar with all its tasks, only for its owner', function () {
    Task::factory()->for($this->project)->create(['title' => 'Done one', 'stage' => TaskStage::Done]);

    $this->withUnencryptedCookie('open_project', (string) $this->project->id)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('openProject.id', $this->project->id)
            ->where('openProject.tasks.0.title', 'Done one')
            ->where('openProject.tasks.0.stage', 'done'));

    $stranger = Project::factory()->create();

    $this->withUnencryptedCookie('open_project', (string) $stranger->id)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('openProject', null));
})->group('TASK-001');

test('the new task page is an empty chat', function () {
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'main chat']);

    $this->get(route('projects.tasks.create', $this->project))
        ->assertInertia(fn ($page) => $page->component('projects/show')
            ->where('newTask', true)
            ->where('task', null)
            ->has('messages', 0));
})->group('TASK-001');

test('a task can be renamed and deleted with its chat, stopping its agent first', function () {
    $task = Task::factory()->for($this->project)->working()->create();
    $task->messages()->create(['role' => MessageRole::User, 'content' => 'hi']);

    $this->patch(route('projects.tasks.update', [$this->project, $task]), ['title' => '  Better   title '])->assertRedirect();
    expect($task->fresh()->title)->toBe('Better title');

    $this->from(route('projects.tasks.show', [$this->project, $task]))
        ->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertRedirect(route('projects.board', $this->project));

    expect(Task::find($task->id))->toBeNull()
        ->and(Message::count())->toBe(0)
        ->and($this->runner->stopped)->toBe(["task-{$task->id}"]);
})->group('TASK-001');

test("only the project's owner can see or change its tasks", function () {
    $task = Task::factory()->for($this->project)->create();
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create());

    $this->get(route('projects.board', $this->project))->assertForbidden();
    $this->get(route('projects.tasks.show', [$this->project, $task]))->assertForbidden();
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'x'])->assertForbidden();
    $this->post(route('projects.tasks.messages.store', [$this->project, $task]), ['content' => 'x'])->assertForbidden();
    $this->delete(route('projects.tasks.destroy', [$this->project, $task]))->assertForbidden();
})->group('TASK-001');

test("a task can't be reached through another project", function () {
    $task = Task::factory()->for($this->project)->create();
    $other = Project::factory()->for($this->user)->create();

    $this->get(route('projects.tasks.show', [$other, $task]))->assertNotFound();
    $this->patch(route('projects.tasks.update', [$other, $task]), ['title' => 'x'])->assertNotFound();
})->group('TASK-001');

test('switching agents while a task works is refused, and starts every task fresh otherwise', function () {
    $task = Task::factory()->for($this->project)->working()->create(['agent_session_id' => 'ses_1']);

    $switch = fn () => $this->patch(route('projects.agent.update', $this->project), [
        'agent_harness' => 'claude_code', 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5',
    ]);

    $switch()->assertSessionHasErrors('agent_harness');

    $task->update(['status' => ProjectStatus::Idle]);
    $switch()->assertSessionHasNoErrors();

    expect($task->fresh()->agent_session_id)->toBeNull();
})->group('TASK-001');
