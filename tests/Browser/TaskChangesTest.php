<?php

use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    if (isset($this->workspace)) {
        File::deleteDirectory($this->workspace);
    }
});

test('the dev user opens a task\'s changes: its commit, uncommitted edit and new file in one diff, with the lockfile folded', function () {
    Queue::fake();
    config(['sandbox.task_copies' => true]);

    // The task's copy runs the real Git tool on a repository in a temporary folder.
    $this->workspace = sys_get_temp_dir().'/onedrop-task-changes-'.uniqid();
    File::ensureDirectoryExists($this->workspace);
    $tools = base_path('docker/sandbox');
    $sh = fn (string $command) => trim(Process::path($this->workspace)->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])->run($command)->throw()->output());
    $commit = 'git -c user.name=Me -c user.email=me@example.com commit -q';

    File::put("{$this->workspace}/app.tsx", "export const title = 'Shop';\n");
    File::put("{$this->workspace}/styles.css", "body { color: black; }\n");
    $sh("git init -q -b main && git add . && {$commit} -m Start");
    $start = $sh('git rev-parse HEAD');
    File::put("{$this->workspace}/app.tsx", "export const title = 'Dark shop';\n");
    $sh("git add . && {$commit} -m 'Dark title'");
    File::put("{$this->workspace}/styles.css", "body { color: white; }\n");
    File::put("{$this->workspace}/theme.ts", "export const dark = true;\n");
    File::put("{$this->workspace}/package-lock.json", "{\"lockfileVersion\": 3}\n");

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) use ($tools) {
        if (! isset($env['APP_GIT_REQUEST'])) {
            return new ExecResult(0, '');
        }

        return new ExecResult(0, Process::env([
            'APP_WORKSPACE' => $this->workspace,
            'APP_CHECKPOINT' => "{$tools}/checkpoint",
            'APP_GIT_REQUEST' => $env['APP_GIT_REQUEST'],
            'GIT_CONFIG_GLOBAL' => '/dev/null',
        ])->run(['php', "{$tools}/git.php"])->output());
    };
    app()->instance(SandboxProvider::class, $provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $user = User::factory()->has(AgentConnection::factory())->create(['email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create(['name' => 'Shop']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'status' => SandboxStatus::Running]);
    $task = Task::factory()->for($project)->create(['title' => 'Dark mode', 'stage' => TaskStage::Review, 'base_commit' => $start]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running, 'preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}/tasks/{$task->id}")
        ->resize(1500, 1000)
        ->click('@task-changes')
        ->assertScript('new URL(location.href).searchParams.get("tab")', 'changes')
        ->assertSeeIn('@changes-since', 'Against Main')
        ->assertSeeIn('@changes-summary', '4 files · 1 commit')
        ->assertSeeIn('@changes-diffs', 'Dark shop')
        ->assertSeeIn('@changes-diffs', 'color: white')
        ->assertSeeIn('@changes-diffs', 'export const dark = true;')
        ->assertDontSeeIn('@changes-diffs', 'lockfileVersion')
        // The lockfile starts folded; opening it shows its diff.
        ->click('[aria-label="Show package-lock.json"]')
        ->assertSeeIn('@changes-diffs', 'lockfileVersion')
        // A reload keeps the tab.
        ->refresh()
        ->assertSeeIn('@changes-since', 'Against Main')
        ->assertNoJavaScriptErrors();

    // Nothing the user staged was touched, and the new files are still untracked.
    expect($sh('git diff --cached --name-only'))->toBe('')
        ->and($sh('git status --porcelain -- theme.ts'))->toBe('?? theme.ts');
})->group('GIT-030');
