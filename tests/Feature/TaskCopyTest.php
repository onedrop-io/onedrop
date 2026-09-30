<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Jobs\DestroySandbox;
use App\Jobs\ForkTaskSandbox;
use App\Jobs\RunAgentTask;
use App\Jobs\SyncTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\TaskCopies;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.task_copies' => true]);

    // The sandbox side of docker/sandbox/fork: snapshots finish at once, branches start at "abc123".
    $this->provider = new FakeSandboxProvider;
    $this->mergeResult = new ExecResult(0, '');
    $this->services = '';
    $this->provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'bash' && ($command[3] ?? null) === 'check' => new ExecResult(0, "done\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'branch' => new ExecResult(0, "abc123\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'merge' => $this->mergeResult,
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'services' => new ExecResult(0, $this->services),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $this->provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->main = Sandbox::factory()->for($this->project)->create(['external_id' => 'main-1', 'status' => SandboxStatus::Running]);
    $this->actingAs($this->user);
});

/**
 * The commands run in a sandbox, by provider id.
 *
 * @return list<list<string>>
 */
function commandsIn(FakeSandboxProvider $provider, string $id): array
{
    return collect($provider->executed)->where('id', $id)->pluck('command')->values()->all();
}

test("a task's first message makes its own copy of the app before its agent runs", function () {
    Bus::fake();

    $this->post(route('projects.tasks.store', $this->project), ['content' => 'Add dark mode']);

    $task = Task::sole();
    expect($task->sandbox->status)->toBe(SandboxStatus::Creating)
        ->and($this->project->sandbox->is($this->main))->toBeTrue();
    Bus::assertChained([ForkTaskSandbox::class, RunAgentTask::class]);
})->group('TASK-003');

test('the copy is Main\'s kept paths at one instant, on its own branch, with its own storage', function () {
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $copy = $task->sandbox()->first();
    $snapshot = commandsIn($this->provider, 'main-1')[0];

    expect($snapshot)->toMatchArray([0 => '/opt/onedrop/fork', 1 => 'snapshot'])
        ->and(array_slice($snapshot, 3))->toBe(['/workspace', '/data/storage', '/home/sandbox'])
        ->and(collect($this->provider->executed)->firstWhere('command', $snapshot)['detach'])->toBeTrue()
        ->and($copy->status)->toBe(SandboxStatus::Running)
        ->and($copy->project_id)->toBe($this->project->id)
        ->and($this->provider->created[$copy->external_id]->storageKey)->toBe("project-{$this->project->id}-task-{$task->id}")
        ->and(commandsIn($this->provider, $copy->external_id))->toContain(['/opt/onedrop/fork', 'branch', "task-{$task->id}"], ['/opt/onedrop/restart'])
        ->and(collect($this->provider->copied)->where(0, 'in')->where(1, $copy->external_id)->pluck(2)->all())->toBe(['/workspace', '/data/storage', '/home/sandbox'])
        ->and($task->fresh()->base_commit)->toBe('abc123')
        ->and($this->project->fresh()->sandbox->is($this->main))->toBeTrue();
})->group('TASK-003');

test('the chat warns about data services outside the sandbox, which the copy still shares', function () {
    $this->services = "db.example.com\ncache.internal\n";
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    expect($task->messages()->pluck('content')->last())->toBe("This copy still uses db.example.com and cache.internal from the app's settings, shared with Main");
})->group('TASK-003');

test("a copy that can't be made says why and stops the run", function () {
    $this->provider->execUsing = fn (array $command) => ($command[3] ?? null) === 'check'
        ? new ExecResult(3, 'No space left on device')
        : new ExecResult(0, '');
    $task = Task::factory()->for($this->project)->working()->create();
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    $job = (new ForkTaskSandbox($task))->withFakeQueueInteractions();
    $job->handle(app(TaskCopies::class), app(AgentQueue::class));

    $job->assertFailed();
    expect($task->messages()->pluck('content')->last())->toBe("I couldn't make this task's copy of the app: Couldn't copy the app: No space left on device")
        ->and($task->fresh()->status)->toBe(ProjectStatus::Idle)
        ->and($task->sandbox()->first()->status)->toBe(SandboxStatus::Failed);
})->group('TASK-003');

test("the task's page shows its own copy, and its tools work on it", function () {
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running, 'preview_url' => 'http://127.0.0.1:9999']);
    $this->main->update(['preview_url' => 'http://127.0.0.1:1111']);

    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page
            ->where('sandbox.preview_url', 'http://127.0.0.1:9999')
            ->where('task.own_copy', true)
            ->where('task.has_copy', true));

    $this->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('sandbox.preview_url', 'http://127.0.0.1:1111'));

    $this->getJson(route('projects.logs.index', $this->project), ['referer' => route('projects.tasks.show', [$this->project, $task])]);
    $this->getJson(route('projects.logs.index', $this->project));

    expect(collect($this->provider->executed)->pluck('id')->unique()->values()->all())->toBe(['copy-1', 'main-1']);
})->group('TASK-003');

test("applying merges the task into Main, hands the rest to Main's agent, and removes the copy", function () {
    Queue::fake();
    $task = Task::factory()->for($this->project)->create(['title' => 'Dark mode', 'stage' => TaskStage::Review]);
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    $this->post(route('projects.tasks.apply', [$this->project, $task]))->assertRedirect();

    expect($task->fresh()->sync_status)->toBe(TaskSyncStatus::Applying);
    Queue::assertPushed(SyncTask::class);

    (new SyncTask($task->fresh(), TaskSyncStatus::Applying))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $task->refresh();
    expect(commandsIn($this->provider, 'copy-1')[0][2])->toContain('/opt/onedrop/fork bundle')
        ->and(collect(commandsIn($this->provider, 'main-1'))->first(fn ($command) => $command[0] === '/opt/onedrop/fork'))->toMatchArray([1 => 'merge', 3 => 'Apply task: Dark mode'])
        ->and($task->stage)->toBe(TaskStage::Done)
        ->and($task->applied_at)->not->toBeNull()
        ->and($task->sync_status)->toBeNull()
        ->and($task->sandbox()->exists())->toBeFalse()
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Working)
        ->and($this->project->messages()->where('role', MessageRole::User)->sole()->content)->toContain('The work from the task “Dark mode”')
        ->and($this->project->messages()->where('role', MessageRole::User)->sole()->content)->not->toContain('conflicts');
    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'copy-1');
    Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->message->task_id === null);
})->group('TASK-003');

test("conflicts are left for Main's agent to resolve", function () {
    Queue::fake();
    $this->mergeResult = new ExecResult(3, "app.css\nroutes/web.php\n");
    $task = Task::factory()->for($this->project)->create(['title' => 'Dark mode']);
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    (new SyncTask($task, TaskSyncStatus::Applying))->handle(app(TaskCopies::class), app(AgentQueue::class));

    expect($this->project->messages()->where('role', MessageRole::User)->sole()->content)
        ->toContain('conflicts in: app.css, routes/web.php')
        ->toContain('git commit --no-edit');
})->group('TASK-003');

test("updating from Main merges Main into the copy and hands it to the task's agent", function () {
    Queue::fake();
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    (new SyncTask($task, TaskSyncStatus::Updating))->handle(app(TaskCopies::class), app(AgentQueue::class));

    expect(collect(commandsIn($this->provider, 'copy-1'))->first(fn ($command) => $command[0] === '/opt/onedrop/fork'))->toMatchArray([1 => 'merge'])
        ->and($task->fresh()->status)->toBe(ProjectStatus::Working)
        ->and($task->messages()->where('role', MessageRole::User)->sole()->content)->toContain('latest work from Main')
        ->and($task->sandbox()->exists())->toBeTrue();
})->group('TASK-003');

test("a task can't be applied while an agent works, or without its own copy", function (Closure $setup, string $message) {
    Queue::fake();
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);
    $setup($this, $task);

    $this->post(route('projects.tasks.apply', [$this->project, $task]))->assertInertiaFlash('toast.message', $message);

    Queue::assertNotPushed(SyncTask::class);
})->with([
    "the task's agent" => [fn ($test, Task $task) => $task->update(['status' => ProjectStatus::Working]), "Wait for the task's agent to finish, or stop it."],
    "Main's agent" => [fn ($test) => $test->project->update(['status' => ProjectStatus::Working]), "Wait for Main's agent to finish, or stop it."],
    'no copy' => [fn ($test, Task $task) => $task->sandbox()->delete(), "This task doesn't have its own copy of the app."],
])->group('TASK-003');

test('a project runs a limited number of task copies at once', function () {
    Queue::fake();
    config(['sandbox.max_task_copies' => 1]);
    $busy = Task::factory()->for($this->project)->create();
    $busy->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    $this->post(route('projects.tasks.store', $this->project), ['content' => 'Another one'])
        ->assertSessionHasErrors(['content' => 'This project already runs 1 task copies of the app. Apply or delete a task first.']);

    expect(Task::count())->toBe(1);
})->group('TASK-003');

test("deleting a task or its project removes the task's copy", function () {
    Queue::fake();
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);
    $other = Task::factory()->for($this->project)->create();
    $other->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-2', 'status' => SandboxStatus::Running]);

    $this->delete(route('projects.tasks.destroy', [$this->project, $task]));

    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'copy-1');
    expect(Sandbox::where('external_id', 'copy-1')->exists())->toBeFalse();

    $this->delete(route('projects.destroy', $this->project));

    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'copy-2');
    Queue::assertPushed(DestroySandbox::class, fn (DestroySandbox $job) => $job->externalId === 'main-1');
})->group('TASK-003');
