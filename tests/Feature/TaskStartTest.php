<?php

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskStart;
use App\Enums\TaskSyncStatus;
use App\Jobs\ForkTaskSandbox;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\TaskCopies;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.task_copies' => true]);

    $this->sha = str_repeat('a', 40);
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'bash' && ($command[3] ?? null) === 'check' => new ExecResult(0, "done\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'branch' => new ExecResult(0, "{$this->sha}\n"),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $this->provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['git_remote_url' => 'https://github.com/acme/app.git', 'github_installation_id' => 77]);
    $this->main = Sandbox::factory()->for($this->project)->create(['external_id' => 'main-1', 'status' => SandboxStatus::Running]);
    $this->actingAs($this->user);

    $this->remote = Mockery::mock(GitRemote::class);
    $this->remote->shouldReceive('heads')->andReturn(['default' => '2.x', 'branches' => ['onedrop-dev', '2.x', 'feature']])->byDefault();
    $this->remote->shouldReceive('fetchesInSandbox')->andReturn(true)->byDefault();
    app()->instance(GitRemote::class, $this->remote);
});

/**
 * A task about to make its copy of the app, as AgentQueue leaves it.
 *
 * @param  array<string, mixed>  $attributes
 */
function startingTask(Project $project, array $attributes): Task
{
    $task = Task::factory()->for($project)->create(['status' => ProjectStatus::Working, 'sync_status' => TaskSyncStatus::Forking, ...$attributes]);
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    return $task;
}

test('a new task starts from the remote\'s default branch unless the user picks a branch, Main or another task', function () {
    Queue::fake();

    $this->post(route('projects.tasks.store', $this->project), ['title' => 'Default'])->assertRedirect();
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'Branch', 'start_from' => 'branch', 'start_branch' => 'onedrop-dev']);
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'Main', 'start_from' => 'main', 'start_branch' => 'ignored']);
    $other = Task::factory()->for($this->project)->create();
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'Copy', 'start_from' => 'task', 'start_task_id' => $other->id]);

    expect($this->project->tasks()->whereIn('title', ['Default', 'Branch', 'Main', 'Copy'])->orderBy('id')->get()->map->only('start_from', 'start_branch', 'start_task_id')->all())->toBe([
        ['start_from' => TaskStart::Branch, 'start_branch' => null, 'start_task_id' => null],
        ['start_from' => TaskStart::Branch, 'start_branch' => 'onedrop-dev', 'start_task_id' => null],
        ['start_from' => TaskStart::Main, 'start_branch' => null, 'start_task_id' => null],
        ['start_from' => TaskStart::Task, 'start_branch' => null, 'start_task_id' => $other->id],
    ]);

    // Another project's task, a bad branch name, and a branch without a remote are refused.
    $stranger = Task::factory()->create();
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'X', 'start_from' => 'task', 'start_task_id' => $stranger->id])->assertSessionHasErrors('start_task_id');
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'X', 'start_from' => 'branch', 'start_branch' => 'bad name'])->assertSessionHasErrors('start_branch');

    $this->project->update(['git_remote_url' => null]);
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'X', 'start_from' => 'branch'])->assertSessionHasErrors('start_from');
    $this->post(route('projects.tasks.store', $this->project), ['title' => 'No remote'])->assertRedirect();
    expect($this->project->tasks()->where('title', 'No remote')->value('start_from'))->toBe(TaskStart::Main);
})->group('TASK-005');

test('the start points list the remote\'s branches, default first, with the open task working on each, and the tasks with a copy', function () {
    $busy = Task::factory()->for($this->project)->create(['title' => 'Dark mode', 'branch' => 'onedrop-dev']);
    $busy->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);
    Task::factory()->for($this->project)->create(['title' => 'Old', 'branch' => 'feature', 'stage' => TaskStage::Done]);

    $this->getJson(route('projects.tasks.start-points', $this->project))
        ->assertOk()
        ->assertJson([
            'copies' => true,
            'remote' => true,
            'default_branch' => '2.x',
            'branches' => [
                ['name' => '2.x', 'task' => null],
                ['name' => 'feature', 'task' => null],
                ['name' => 'onedrop-dev', 'task' => ['id' => $busy->id, 'title' => 'Dark mode']],
            ],
            'tasks' => [['id' => $busy->id, 'title' => 'Dark mode']],
            'error' => null,
        ]);

    $this->remote->shouldReceive('heads')->andThrow(new GitException("GitHub didn't accept the token."));
    $this->getJson(route('projects.tasks.start-points', $this->project))->assertOk()->assertJsonPath('error', "GitHub didn't accept the token.");
})->group('TASK-005');

test('a task started from a branch is a fresh clone of it: on that branch, or its own off the default branch or a busy one', function (?string $picked, ?string $busy, string $expected) {
    Queue::fake([RunAgentTask::class]);

    if ($busy !== null) {
        Task::factory()->for($this->project)->create(['branch' => $busy]);
    }

    $task = startingTask($this->project, ['title' => 'Dark mode', 'start_from' => TaskStart::Branch, 'start_branch' => $picked]);
    $from = $picked ?? '2.x';
    $branch = $expected === 'own' ? "onedrop/task-{$task->id}-dark-mode" : $expected;
    $this->remote->shouldReceive('checkOutBranchInSandbox')->once()
        ->withArgs(fn (Project $project, Sandbox $sandbox, string $source, string $work) => $sandbox->task_id === $task->id && [$source, $work] === [$from, $branch])
        ->andReturn($this->sha);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    expect($task->fresh()->only('branch', 'start_branch', 'base_commit'))->toBe(['branch' => $branch, 'start_branch' => $from, 'base_commit' => $this->sha])
        ->and($task->sandbox()->first()->status)->toBe(SandboxStatus::Running)
        // Main isn't snapshotted or copied.
        ->and(collect($this->provider->executed)->where('id', 'main-1'))->toBeEmpty()
        ->and($task->messages()->pluck('content')->all())->toContain(
            'Checking out '.($picked ?? "the repository's default branch").' in a fresh sandbox for this task',
            $branch === $from ? "Working on {$branch}" : "Working on {$branch}, a new branch off {$from}",
        );
})->with([
    'the default branch' => [null, null, 'own'],
    'a free branch' => ['onedrop-dev', null, 'onedrop-dev'],
    'a branch another task works on' => ['onedrop-dev', 'onedrop-dev', 'own'],
])->group('TASK-005');

test('a token remote\'s branch is fetched on the platform, and a branch gone from the remote says so', function () {
    Queue::fake([RunAgentTask::class]);
    $this->remote->shouldReceive('fetchesInSandbox')->andReturn(false);
    $this->provider->execUsing = fn (array $command, array $env) => new ExecResult(0, isset($env['APP_GIT_REQUEST'])
        ? json_encode(['ok' => true, 'data' => ['initialized' => true, 'branch' => 'x', 'branches' => [], 'head' => $this->sha, 'staged' => [], 'changes' => [], 'more_changes' => false, 'tracking' => null, 'state' => null]])
        : '');
    $task = startingTask($this->project, ['title' => 'Dark mode', 'start_from' => TaskStart::Branch]);
    $this->remote->shouldReceive('pull')->once()->withArgs(fn (Project $project, string $branch, Sandbox $into) => $branch === '2.x' && $into->task_id === $task->id);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    expect($task->fresh()->branch)->toBe("onedrop/task-{$task->id}-dark-mode")
        ->and(collect($this->provider->executed)->pluck('env.APP_GIT_REQUEST')->filter()->map(fn (string $json) => json_decode($json, true))->contains(['op' => 'rename_branch', 'branch' => "onedrop/task-{$task->id}-dark-mode"]))->toBeTrue();

    $gone = startingTask($this->project, ['start_from' => TaskStart::Branch, 'start_branch' => 'deleted']);
    $job = (new ForkTaskSandbox($gone))->withFakeQueueInteractions();
    $job->handle(app(TaskCopies::class), app(AgentQueue::class));

    $job->assertFailed();
    expect($gone->sandbox()->first()->status)->toBe(SandboxStatus::Failed)
        ->and($gone->messages()->pluck('content')->last())->toBe("I couldn't make this task's copy of the app: The repository has no branch called deleted any more.");
})->group('TASK-005');

test('a task can copy another task\'s copy instead of Main, and Main again once its work was applied', function () {
    Queue::fake([RunAgentTask::class]);
    $source = Task::factory()->for($this->project)->create(['title' => 'Dark mode']);
    $source->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);
    $task = startingTask($this->project, ['start_from' => TaskStart::Task, 'start_task_id' => $source->id]);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $snapshotted = fn (string $id) => collect($this->provider->executed)->where('id', $id)->pluck('command')->contains(fn (array $command) => ($command[1] ?? null) === 'snapshot');
    expect($snapshotted('copy-1'))->toBeTrue()
        ->and($snapshotted('main-1'))->toBeFalse()
        ->and($task->fresh()->branch)->toBe("task-{$task->id}")
        ->and($task->messages()->pluck('content')->first())->toBe('Making a copy of "Dark mode" for this task');

    // Its work was applied to Main: its next copy is Main's, which has it.
    expect(Task::factory()->make(['start_from' => TaskStart::Branch, 'applied_at' => now()])->startsFrom())->toBe(TaskStart::Main)
        ->and(Task::factory()->make(['start_from' => null])->startsFrom())->toBe(TaskStart::Main);

    // A source task without a running copy can't be copied.
    $source->sandbox()->update(['status' => SandboxStatus::Failed]);
    $again = startingTask($this->project, ['start_from' => TaskStart::Task, 'start_task_id' => $source->id]);
    $job = (new ForkTaskSandbox($again))->withFakeQueueInteractions();
    $job->handle(app(TaskCopies::class), app(AgentQueue::class));

    $job->assertFailed();
    expect($again->messages()->pluck('content')->last())->toBe("I couldn't make this task's copy of the app: \"Dark mode\" doesn't have a running copy of the app to start from.");
})->group('TASK-005');

test('the remote\'s branches and default branch come from one ls-remote', function () {
    app()->forgetInstance(GitRemote::class);
    Process::fake(['*ls-remote*' => Process::result("ref: refs/heads/2.x\tHEAD\n{$this->sha}\tHEAD\n{$this->sha}\trefs/heads/2.x\n{$this->sha}\trefs/heads/onedrop-dev\n")]);
    $this->project->update(['github_installation_id' => null, 'git_remote_token' => 't']);

    expect(app(GitRemote::class)->heads($this->project))->toBe(['default' => '2.x', 'branches' => ['2.x', 'onedrop-dev']]);
})->group('TASK-005');
