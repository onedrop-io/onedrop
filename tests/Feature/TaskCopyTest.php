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
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['sandbox.task_copies' => true]);

    // The sandbox side of docker/sandbox/fork: snapshots finish at once, branches start at "abc123".
    $this->provider = new FakeSandboxProvider;
    $this->mergeResult = new ExecResult(0, '');
    $this->services = '';
    $this->images = '';
    $this->provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'bash' && ($command[3] ?? null) === 'check' => new ExecResult(0, "done\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'branch' => new ExecResult(0, "abc123\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'merge' => $this->mergeResult,
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'services' => new ExecResult(0, $this->services),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'images' => new ExecResult(0, $this->images),
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
    // The first after checking Main's tool files (SBX-002).
    $snapshot = collect(commandsIn($this->provider, 'main-1'))->reject(fn (array $command) => str_contains($command[2] ?? '', 'ONEDROP_TOOL_PATHS'))->first();

    expect($snapshot)->toMatchArray([0 => '/opt/onedrop/fork', 1 => 'snapshot'])
        ->and(array_slice($snapshot, 3))->toBe(['/workspace', '/data/storage', '/home/sandbox'])
        ->and(collect($this->provider->executed)->firstWhere('command', $snapshot)['detach'])->toBeTrue()
        // As root: files a database's container owns as its own user are copied too, and cleared up after.
        ->and(collect($this->provider->executed)->firstWhere('command', $snapshot)['root'])->toBeTrue()
        ->and(collect($this->provider->executed)->where('id', 'main-1')->first(fn (array $exec) => $exec['command'][0] === 'rm')['root'])->toBeTrue()
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

test('the copy gets the Docker images Main built itself, each stored once and loaded through signed links', function () {
    config(['filesystems.disks.local.driver' => 's3', 'sandbox.snapshot_disk' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?upload", 'headers' => ['Content-Type' => 'application/zstd']]);
    $folder = "project-snapshots/{$this->project->id}/images";
    // Stored for an earlier task: loaded without saving it again. An image Main has since built again goes.
    Storage::disk('local')->put("{$folder}/bbb.tar.zst", 'api');
    Storage::disk('local')->put("{$folder}/old.tar.zst", 'old');
    $this->images = "sha256:aaa ghcr.io/acme/app:latest ghcr.io/acme/app:dev\nsha256:bbb ghcr.io/acme/api:latest\n";
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $copy = $task->sandbox()->first();
    $saves = collect($this->provider->executed)->filter(fn (array $exec) => ($exec['command'][1] ?? null) === 'save-image');
    $loads = collect($this->provider->executed)->filter(fn (array $exec) => ($exec['command'][1] ?? null) === 'load-image');
    $copyCommands = commandsIn($this->provider, $copy->external_id);

    expect($saves)->toHaveCount(1)
        ->and($saves->first()['id'])->toBe('main-1')
        ->and(array_slice($saves->first()['command'], 4))->toBe(['ghcr.io/acme/app:latest', 'ghcr.io/acme/app:dev'])
        ->and($saves->first()['env'])->toBe(['ONEDROP_IMAGE_URL' => "https://bucket.test/{$folder}/aaa.tar.zst?upload", 'ONEDROP_IMAGE_HEADERS' => 'Content-Type: application/zstd'])
        ->and($saves->first()['detach'])->toBeTrue()
        ->and($loads)->toHaveCount(2)
        ->and($loads->pluck('id')->unique()->all())->toBe([$copy->external_id])
        ->and($loads->pluck('command')->map(fn (array $command) => $command[3])->all())->toBe(['url', 'url'])
        ->and($loads->pluck('env')->map(fn (array $env) => str_contains($env['ONEDROP_IMAGE_URL'], 'bbb.tar.zst'))->all())->toBe([false, true])
        // Loaded before the app starts, so its compose stack finds them.
        ->and(array_search(['/opt/onedrop/restart'], $copyCommands, true))->toBeGreaterThan(collect($copyCommands)->search(fn (array $command) => ($command[1] ?? null) === 'load-image'))
        ->and($copy->status)->toBe(SandboxStatus::Running);
    Storage::disk('local')->assertMissing("{$folder}/old.tar.zst");
    Storage::disk('local')->assertExists("{$folder}/bbb.tar.zst");
})->group('TASK-003');

test("a copy whose images can't be carried still starts, and builds them itself", function () {
    config(['filesystems.disks.local.driver' => 's3', 'sandbox.snapshot_disk' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}", 'headers' => []]);
    $this->images = "sha256:aaa ghcr.io/acme/app:latest\n";
    $this->provider->execUsing = fn (array $command) => match (true) {
        ($command[3] ?? null) === 'check' && str_contains($command[4], 'onedrop-image') => new ExecResult(3, 'no space left on device'),
        ($command[3] ?? null) === 'check' => new ExecResult(0, "done\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'images' => new ExecResult(0, $this->images),
        default => new ExecResult(0, ''),
    };
    $task = Task::factory()->for($this->project)->create();
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    (new ForkTaskSandbox($task))->handle(app(TaskCopies::class), app(AgentQueue::class));

    expect($task->sandbox()->first()->status)->toBe(SandboxStatus::Running)
        ->and(commandsIn($this->provider, $task->sandbox()->first()->external_id))->toContain(['/opt/onedrop/restart']);
})->group('TASK-003');

test("a copy that can't be made says why and stops the run", function () {
    $this->provider->execUsing = fn (array $command) => ($command[3] ?? null) === 'check'
        ? new ExecResult(3, "No space left on device\nrsync error: some files could not be transferred")
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

test('with no limit set anywhere, a project runs as many task copies as it has tasks', function () {
    Queue::fake();
    config(['sandbox.max_task_copies' => null]);

    foreach (range(1, 5) as $index) {
        $busy = Task::factory()->for($this->project)->create();
        $busy->sandbox()->create(['provider' => 'fake', 'external_id' => "copy-{$index}", 'status' => SandboxStatus::Running]);
    }

    $this->post(route('projects.tasks.store', $this->project), ['content' => 'Another one'])->assertSessionHasNoErrors();

    expect(Task::count())->toBe(6);
})->group('TASK-003');

test('the lower of the install\'s and the organization\'s task copy limits applies', function (?int $install, ?int $organization, ?int $limit) {
    config(['sandbox.max_task_copies' => $install]);
    $this->project->organization->update(['max_task_copies' => $organization]);

    expect($this->project->fresh()->taskCopyLimit())->toBe($limit);
})->with([
    'neither' => [null, null, null],
    'the install only' => [4, null, 4],
    'the organization only' => [null, 2, 2],
    'the organization lower' => [5, 2, 2],
    'the install lower' => [2, 5, 2],
])->group('TASK-003');

test('a project at its limit can\'t start another task copy', function () {
    Queue::fake();
    config(['sandbox.max_task_copies' => null]);
    $this->project->organization->update(['max_task_copies' => 1]);
    $busy = Task::factory()->for($this->project)->create();
    $busy->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    $this->post(route('projects.tasks.store', $this->project), ['content' => 'Another one'])
        ->assertSessionHasErrors(['content' => 'This project already runs 1 task copy of the app, as many as it may. Apply or delete a task first.']);

    expect(Task::count())->toBe(1);
})->group('TASK-003');

test('a task applied to a Main that leaves its changes uncommitted lands as uncommitted changes, and the agent is told not to commit', function () {
    Queue::fake();
    $this->project->update(['commit_turns' => false]);
    $this->mergeResult = new ExecResult(3, "app.css\n");
    $task = Task::factory()->for($this->project)->create(['title' => 'Dark mode']);
    $task->sandbox()->create(['provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    (new SyncTask($task, TaskSyncStatus::Applying))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $merge = collect($this->provider->executed)->first(fn (array $run) => $run['id'] === 'main-1' && $run['command'][0] === '/opt/onedrop/fork');
    $bundle = collect($this->provider->executed)->first(fn (array $run) => $run['id'] === 'copy-1' && str_contains(implode(' ', $run['command']), 'fork bundle'));

    expect($merge['env'])->toBe(['ONEDROP_COMMIT_TURNS' => '0'])
        ->and($bundle['env'])->toBe([])
        ->and($this->project->messages()->where('role', MessageRole::User)->sole()->content)
        ->toContain('as uncommitted changes')
        ->toContain('marked with <<<<<<<')
        ->toContain("Don't commit")
        ->not->toContain('git commit --no-edit');
})->group('TASK-003', 'SCM-003');

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

test("the fork tool switches a pull request's task copy to its branch at the bundle's HEAD, keeping ignored files", function () {
    $root = sys_get_temp_dir().'/onedrop-fork-'.uniqid();
    $sh = fn (string $command, string $path) => trim(Process::path($path)->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])->run($command)->throw()->output());
    $commit = '-c user.name=Me -c user.email=me@example.com commit -q';
    File::ensureDirectoryExists("{$root}/workspace");
    File::ensureDirectoryExists("{$root}/pr");
    // Main's copy: committed work, an uncommitted change, and an ignored .env.
    $sh("git init -q -b main && printf '.env\\n' > .gitignore && echo main > a.txt && git add . && git {$commit} -m Main && echo SECRET=1 > .env && echo edited > a.txt", "{$root}/workspace");
    // The pull request: its own history.
    $sh("git init -q -b fix && echo pr > a.txt && echo new > b.txt && git add . && git {$commit} -m PR && git bundle create -q ../pull.bundle HEAD fix", "{$root}/pr");
    $head = $sh('git rev-parse HEAD', "{$root}/pr");

    // Stands in for the checkpoint tool: commit everything.
    file_put_contents("{$root}/checkpoint", "#!/bin/sh\ngit add -A && git -c user.name=Me -c user.email=me@example.com commit -q -m Checkpoint\n");
    chmod("{$root}/checkpoint", 0755);

    $output = $sh(implode(' ', [
        'ONEDROP_WORKSPACE='.escapeshellarg("{$root}/workspace"),
        'ONEDROP_CHECKPOINT='.escapeshellarg("{$root}/checkpoint"),
        'bash', base_path('docker/sandbox/fork'), 'checkout', escapeshellarg("{$root}/pull.bundle"), 'fix-login',
    ]), "{$root}/workspace");

    expect($output)->toBe($head)
        ->and($sh('git branch --show-current', "{$root}/workspace"))->toBe('fix-login')
        ->and(file_get_contents("{$root}/workspace/a.txt"))->toBe("pr\n")
        ->and(file_get_contents("{$root}/workspace/.env"))->toBe("SECRET=1\n")
        // Main's uncommitted change was kept on Main's branch, not lost.
        ->and($sh('git show main:a.txt', "{$root}/workspace"))->toBe('edited');

    File::deleteDirectory($root);
})->group('GIT-014');

/**
 * Run the fork tool in $workspace with the real checkpoint tool, as Main or a task's copy.
 *
 * @param  list<string>  $args
 */
function forkTool(string $workspace, array $args, bool $uncommitted): ProcessResult
{
    return Process::path($workspace)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'ONEDROP_WORKSPACE' => $workspace,
        'ONEDROP_CHECKPOINT' => base_path('docker/sandbox/checkpoint'),
        ...($uncommitted ? ['ONEDROP_COMMIT_TURNS' => '0'] : []),
    ])->run(['bash', base_path('docker/sandbox/fork'), ...$args]);
}

test('a Main that leaves its changes uncommitted gets a task\'s work as uncommitted changes, and sends its own without committing', function () {
    $root = sys_get_temp_dir().'/onedrop-fork-'.uniqid();
    $sh = fn (string $command, string $path) => trim(Process::path($path)->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])->run($command)->throw()->output());
    $commit = '-c user.name=Me -c user.email=me@example.com commit -q';
    File::ensureDirectoryExists("{$root}/main");
    // Main: a commit, then uncommitted work (an edit, a new file, something staged).
    $sh("git init -q -b main && printf 'one\\ntwo\\nthree\\n' > a.txt && echo keep > gone.txt && git add . && git {$commit} -m Main", "{$root}/main");
    $sh('cp -a main copy', $root);
    $sh("printf 'one\\ntwo\\nthree, edited on Main\\n' > a.txt && echo staged > staged.txt && git add staged.txt", "{$root}/main");
    // The task's copy: its own commits on its branch.
    forkTool("{$root}/copy", ['branch', 'task-1'], uncommitted: false)->throw();
    $sh("printf 'one, edited by the task\\ntwo\\nthree\\n' > a.txt && echo task > task.txt && rm gone.txt && git add -A && git {$commit} -m Task", "{$root}/copy");
    forkTool("{$root}/copy", ['bundle', "{$root}/branch.bundle", 'Task: Edit'], uncommitted: false)->throw();

    $applied = forkTool("{$root}/main", ['merge', "{$root}/branch.bundle", 'Apply task: Edit'], uncommitted: true);

    expect($applied->exitCode())->toBe(0)
        ->and($sh('git log --format=%s', "{$root}/main"))->toBe('Main')
        ->and(file_get_contents("{$root}/main/a.txt"))->toBe("one, edited by the task\ntwo\nthree, edited on Main\n")
        ->and(file_get_contents("{$root}/main/task.txt"))->toBe("task\n")
        ->and(File::exists("{$root}/main/gone.txt"))->toBeFalse()
        ->and($sh('git diff --cached --name-only', "{$root}/main"))->toBe('staged.txt')
        ->and($sh("git log -2 --format='%s|%(trailers:key=Onedrop-Kind,valueonly,separator=)' refs/onedrop/checkpoints", "{$root}/main"))->toBe("Apply task: Edit|apply\nChanges outside the agent|edits");

    // Update from Main: Main's files travel without a commit on its branch.
    forkTool("{$root}/main", ['bundle', "{$root}/main.bundle", 'Checkpoint'], uncommitted: true)->throw();
    $updated = forkTool("{$root}/copy", ['merge', "{$root}/main.bundle", 'Update from Main'], uncommitted: false);

    expect($updated->exitCode())->toBe(0)
        ->and($sh('git log --format=%s', "{$root}/main"))->toBe('Main')
        ->and(file_get_contents("{$root}/copy/a.txt"))->toBe("one, edited by the task\ntwo\nthree, edited on Main\n")
        ->and(file_get_contents("{$root}/copy/staged.txt"))->toBe("staged\n");

    File::deleteDirectory($root);
})->group('TASK-003', 'SCM-003');

test('conflicts applied to an uncommitted Main are left as conflict markers, with nothing committed', function () {
    $root = sys_get_temp_dir().'/onedrop-fork-'.uniqid();
    $sh = fn (string $command, string $path) => trim(Process::path($path)->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])->run($command)->throw()->output());
    $commit = '-c user.name=Me -c user.email=me@example.com commit -q';
    File::ensureDirectoryExists("{$root}/main");
    $sh("git init -q -b main && echo start > a.txt && git add . && git {$commit} -m Main", "{$root}/main");
    $sh('cp -a main copy', $root);
    $sh('echo main > a.txt', "{$root}/main");
    forkTool("{$root}/copy", ['branch', 'task-1'], uncommitted: false)->throw();
    $sh("echo task > a.txt && git {$commit} -am Task", "{$root}/copy");
    forkTool("{$root}/copy", ['bundle', "{$root}/branch.bundle", 'Task: Edit'], uncommitted: false)->throw();

    $applied = forkTool("{$root}/main", ['merge', "{$root}/branch.bundle", 'Apply task: Edit'], uncommitted: true);

    expect($applied->exitCode())->toBe(3)
        ->and(trim($applied->output()))->toBe('a.txt')
        ->and(file_get_contents("{$root}/main/a.txt"))->toContain('<<<<<<<')->toContain('main')->toContain('task')
        ->and(File::exists("{$root}/main/.git/MERGE_HEAD"))->toBeFalse()
        ->and($sh('git log --format=%s', "{$root}/main"))->toBe('Main');

    File::deleteDirectory($root);
})->group('TASK-003', 'SCM-003');
