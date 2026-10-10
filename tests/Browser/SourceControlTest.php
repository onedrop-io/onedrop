<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * A sandbox whose git is the real Git tool (docker/sandbox/git.php) on a repository in a temporary folder: a commit,
 * then an agent turn saved only as a private checkpoint, leaving an edit in two places and a new file uncommitted.
 */
function sourceControlSandbox(object $test): void
{
    $test->workspace = sys_get_temp_dir().'/onedrop-scm-'.uniqid();
    File::ensureDirectoryExists($test->workspace);
    $sandbox = base_path('docker/sandbox');
    $test->sh = fn (string $command) => trim(Process::path($test->workspace)->env(['GIT_CONFIG_GLOBAL' => '/dev/null'])->run($command)->throw()->output());

    $lines = array_map(fn (int $n) => "line {$n}", range(1, 20));
    File::put("{$test->workspace}/app.tsx", implode("\n", $lines)."\n");
    ($test->sh)('git init -q -b main && git add . && git -c user.name=Me -c user.email=me@example.com commit -q -m "Start"');
    $lines[1] = 'line 2, by the agent';
    $lines[18] = 'line 19, by the agent';
    File::put("{$test->workspace}/app.tsx", implode("\n", $lines)."\n");
    File::put("{$test->workspace}/timer.tsx", "export const Timer = () => null;\n");
    Process::path($test->workspace)
        ->env(['ONEDROP_WORKSPACE' => $test->workspace, 'ONEDROP_COMMIT_TURNS' => '0'])
        ->input('Build a timer')
        ->run(["{$sandbox}/checkpoint"])
        ->throw();

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) use ($test, $sandbox) {
        if (! isset($env['APP_GIT_REQUEST'])) {
            return new ExecResult(0, '');
        }

        $result = Process::env([
            'APP_WORKSPACE' => $test->workspace,
            'APP_CHECKPOINT' => "{$sandbox}/checkpoint",
            'APP_GIT_REQUEST' => $env['APP_GIT_REQUEST'],
            'GIT_CONFIG_GLOBAL' => '/dev/null',
        ])->run(['php', "{$sandbox}/git.php"]);

        return new ExecResult(0, $result->output());
    };
    app()->instance(SandboxProvider::class, $provider);
}

afterEach(function () {
    if (isset($this->workspace)) {
        File::deleteDirectory($this->workspace);
    }
});

test('source control stages a part of a file and a whole file, commits them as the dev user, and restores a checkpoint', function () {
    Queue::fake();
    sourceControlSandbox($this);
    $user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create(['git_remote_url' => 'https://github.com/dev/timer.git']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    expect($project->commit_turns)->toBeFalse();

    $page = visit("/projects/{$project->id}?tab=source-control")
        ->resize(2200, 1100)
        ->assertVisible('@source-control')
        ->assertSeeIn('@git-branch', 'main')
        ->assertSeeIn('@git-changes', 'app.tsx')
        ->assertSeeIn('@git-changes', 'timer.tsx')
        ->assertSeeIn('@git-changes-count', '2')
        ->assertSeeIn('@scm-timeline', 'Build a timer')
        ->assertSeeIn('@scm-commit', 'Commit all (2)');

    $page->screenshot(filename: 'source-control');

    // Stage the first part of app.tsx: it's staged and still has unstaged changes.
    $page->click('[data-test="git-changes"] [data-test="git-change"]:first-child [data-test="git-change-open"]')
        ->assertSeeIn('@scm-diff-path', 'app.tsx')
        ->assertSeeIn('@scm-diff', 'line 2, by the agent')
        ->click('[data-test="git-actions-hunk"]:first-child [data-test="scm-hunk-stage"]')
        ->assertSeeIn('@scm-staged', 'app.tsx')
        ->assertSeeIn('@scm-commit', 'Commit (1)');

    $page->screenshot(filename: 'source-control-diff');

    expect(($this->sh)('git show :app.tsx'))->toContain('line 2, by the agent')->not->toContain('line 19, by the agent');

    // Stage timer.tsx from its row, then commit what's staged with a message.
    $page->hover('[data-test="git-changes"] [data-test="git-change"]:last-child')
        ->click('[data-test="git-changes"] [data-test="git-change"]:last-child [data-test="git-change-stage"]')
        ->assertSeeIn('@scm-staged', 'timer.tsx')
        ->type('@scm-message', 'Add the timer')
        ->click('@scm-commit')
        ->assertMissing('@scm-staged')
        ->assertSeeIn('@git-changes', 'app.tsx');

    expect(($this->sh)('git log --format="%an|%s"'))->toBe("Dev User|Add the timer\nMe|Start")
        ->and(($this->sh)('git status --porcelain'))->toBe('M app.tsx');

    // Restore the files from before the agent's turn: nothing is committed, the restore is in the Timeline.
    $page->click('[data-test="scm-checkpoint"]:last-child')
        ->assertSeeIn('@scm-detail', 'Build a timer')
        ->click('@scm-restore-before')
        ->click('@git-confirm-action')
        ->assertSeeIn('@scm-timeline', 'Restore before "Build a timer"');

    expect(File::exists("{$this->workspace}/timer.tsx"))->toBeFalse()
        ->and(File::get("{$this->workspace}/app.tsx"))->not->toContain('by the agent')
        ->and(($this->sh)('git log --format=%s'))->toBe("Add the timer\nStart");

    // The agent can be set to commit each turn again.
    $page->click('@scm-commit-turns-switch')
        ->assertAttribute('@scm-commit-turns-switch', 'aria-checked', 'true');

    expect($project->fresh()->commit_turns)->toBeTrue();
})->group('SCM-001', 'SCM-002', 'SCM-003');

test('the header\'s Commit button opens source control with the cursor in the message box', function () {
    sourceControlSandbox($this);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->assertSeeIn('@git-actions-count', '2')
        ->click('@git-actions-primary')
        ->assertVisible('@source-control')
        ->assertScript('document.activeElement?.dataset.test', 'scm-message')
        ->assertQueryStringHas('tab', 'source-control');
})->group('SCM-001', 'GIT-006');

test('old links to Source Control open the source control tab', function () {
    sourceControlSandbox($this);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}?tool=git")
        ->resize(1500, 1000)
        ->assertVisible('@source-control')
        ->assertSeeIn('@scm-timeline', 'Build a timer');
})->group('SCM-001');
