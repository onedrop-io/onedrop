<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Jobs\ForkTaskSandbox;
use App\Jobs\LinkTaskPullRequest;
use App\Jobs\OpenTaskPullRequest;
use App\Jobs\RefreshPullRequestTasks;
use App\Jobs\RunAgentTask;
use App\Jobs\SyncPullRequestTask;
use App\Jobs\WatchPullRequestChecks;
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
use App\Sandbox\GitHubApp;
use App\Sandbox\GitHubPulls;
use App\Sandbox\GitRemote;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\PullRequestFixes;
use App\Sandbox\PullRequestTasks;
use App\Sandbox\PullRequestWriter;
use App\Sandbox\SandboxProvider;
use App\Sandbox\TaskCopies;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['sandbox.task_copies' => true]);
    Http::preventStrayRequests();

    $this->sha = str_repeat('a', 40);
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = fn (array $command) => match (true) {
        $command[0] === 'bash' && ($command[3] ?? null) === 'check' => new ExecResult(0, "done\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'checkout' => new ExecResult(0, "{$this->sha}\n"),
        $command[0] === '/opt/onedrop/fork' && $command[1] === 'merge' => $this->mergeResult ?? new ExecResult(0, ''),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $this->provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'git_remote_url' => 'https://github.com/acme/app.git',
        'git_remote_token' => 'ghp_token',
    ]);
    $this->main = Sandbox::factory()->for($this->project)->create(['external_id' => 'main-1', 'status' => SandboxStatus::Running]);
    $this->actingAs($this->user);

    $this->pull = [
        'number' => 12, 'title' => 'Fix login', 'body' => 'Fixes the **login** form.', 'state' => 'open', 'draft' => false, 'merged' => false,
        'html_url' => 'https://github.com/acme/app/pull/12', 'user' => ['login' => 'sam', 'avatar_url' => 'https://avatars.test/sam'],
        'created_at' => '2026-10-01T10:00:00Z', 'updated_at' => '2026-10-02T10:00:00Z',
        'head' => ['ref' => 'fix-login', 'sha' => $this->sha, 'repo' => ['full_name' => 'acme/app']],
        'base' => ['ref' => 'main'], 'mergeable' => true, 'mergeable_state' => 'clean',
        'commits' => 2, 'additions' => 10, 'deletions' => 2, 'changed_files' => 3, 'comments' => 1, 'review_comments' => 1,
    ];
    $this->checkRuns = [
        ['id' => 501, 'name' => 'tests', 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'github-actions'], 'started_at' => '2026-10-02T10:00:00Z', 'completed_at' => '2026-10-02T10:03:00Z', 'html_url' => 'https://github.com/acme/app/actions/runs/1/job/501', 'output' => ['summary' => null]],
        ['id' => 502, 'name' => 'lint', 'status' => 'completed', 'conclusion' => 'success', 'app' => ['slug' => 'github-actions'], 'started_at' => null, 'completed_at' => null, 'html_url' => null, 'output' => []],
    ];
    $this->statuses = [];
    $this->log = "2026-10-02T10:02:59.1234567Z \e[31mFAILED\e[0m Tests\\LoginTest > it logs in\n2026-10-02T10:03:00.0000000Z Expected 200, got 500";

    // GitHub, answering for acme/app with the project's token.
    $this->github = function (array $routes = []) {
        Http::fake(array_merge([
            'api.github.com/graphql' => fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer ghp_token')
                ? Http::response(['data' => ['repository' => ['pullRequests' => ['nodes' => [[
                    'number' => 12, 'title' => 'Fix login', 'state' => 'OPEN', 'isDraft' => false, 'url' => 'https://github.com/acme/app/pull/12',
                    'updatedAt' => '2026-10-02T10:00:00Z', 'headRefName' => 'fix-login', 'baseRefName' => 'main', 'reviewDecision' => 'CHANGES_REQUESTED',
                    'author' => ['login' => 'sam', 'avatarUrl' => 'https://avatars.test/sam'], 'headRepository' => ['nameWithOwner' => 'acme/app'],
                    'comments' => ['totalCount' => 3], 'commits' => ['nodes' => [['commit' => ['oid' => $this->sha, 'statusCheckRollup' => ['state' => 'FAILURE']]]]],
                ], [
                    'number' => 9, 'title' => 'From a fork', 'state' => 'OPEN', 'isDraft' => true, 'url' => 'https://github.com/acme/app/pull/9',
                    'updatedAt' => '2026-09-30T10:00:00Z', 'headRefName' => 'main', 'baseRefName' => 'main', 'reviewDecision' => null,
                    'author' => null, 'headRepository' => ['nameWithOwner' => 'someone/app'],
                    'comments' => ['totalCount' => 0], 'commits' => ['nodes' => [['commit' => ['oid' => 'b', 'statusCheckRollup' => null]]]],
                ]]]]]])
                : Http::response(['message' => 'Bad credentials'], 401),
            'api.github.com/repos/acme/app/pulls/12' => fn () => Http::response($this->pull),
            'api.github.com/repos/acme/app/issues/12/comments*' => Http::response([
                ['user' => ['login' => 'dev', 'avatar_url' => null], 'body' => 'Looks close.', 'created_at' => '2026-10-01T12:00:00Z', 'html_url' => 'https://github.com/c/1'],
            ]),
            'api.github.com/repos/acme/app/pulls/12/reviews*' => Http::response([
                ['user' => ['login' => 'dev', 'avatar_url' => null], 'body' => 'Needs a test.', 'state' => 'CHANGES_REQUESTED', 'submitted_at' => '2026-10-01T11:00:00Z'],
                ['user' => ['login' => 'dev', 'avatar_url' => null], 'body' => '', 'state' => 'PENDING', 'submitted_at' => null],
            ]),
            'api.github.com/repos/acme/app/pulls/12/comments*' => Http::response([
                ['user' => ['login' => 'dev', 'avatar_url' => null], 'body' => 'Typo here.', 'path' => 'app/Login.php', 'line' => 14, 'created_at' => '2026-10-01T13:00:00Z'],
            ]),
            'api.github.com/repos/acme/app/pulls/12/commits*' => Http::response([
                ['sha' => $this->sha, 'commit' => ['message' => "Fix login\n\nDetails", 'author' => ['name' => 'Sam', 'date' => '2026-10-01T10:00:00Z']], 'author' => ['login' => 'sam'], 'html_url' => 'https://github.com/acme/app/commit/a'],
            ]),
            'api.github.com/repos/acme/app/pulls/12/files*' => Http::response([
                ['filename' => 'app/Login.php', 'status' => 'modified', 'additions' => 2, 'deletions' => 1, 'changes' => 3, 'patch' => "@@ -1,2 +1,3 @@\n-old\n+new\n+more"],
                ['filename' => 'logo.png', 'status' => 'added', 'additions' => 0, 'deletions' => 0, 'changes' => 0],
                ['filename' => 'package-lock.json', 'status' => 'modified', 'additions' => 9000, 'deletions' => 10, 'changes' => 9010],
            ]),
            "api.github.com/repos/acme/app/commits/{$this->sha}/check-runs*" => fn () => Http::response(['check_runs' => $this->checkRuns]),
            "api.github.com/repos/acme/app/commits/{$this->sha}/status" => fn () => Http::response(['statuses' => $this->statuses]),
            'api.github.com/repos/acme/app/actions/jobs/501/logs' => fn () => Http::response($this->log),
        ], $routes));
    };
});

/**
 * GitRemote for a pull request's task, without git: fetching writes a bundle and says the pull request's head is
 * $this->remoteSha; pushing records the branch and returns $this->pushedSha.
 */
function fakePullRequestRemote(object $test): void
{
    $test->remoteSha ??= $test->sha;
    $test->pushes = [];
    $remote = Mockery::mock(GitRemote::class);
    // A token remote: the platform fetches the pull request's commits and hands them over.
    $remote->shouldReceive('fetchesInSandbox')->andReturn(false);
    $remote->shouldReceive('fetchPullRequest')->andReturnUsing(function (Project $project, int $number, string $directory) use ($test) {
        File::ensureDirectoryExists($directory);
        File::put("{$directory}/pull.bundle", 'bundle');

        return $test->remoteSha;
    });
    $remote->shouldReceive('pushBundle')->andReturnUsing(function (Project $project, string $bundle, string $branch) use ($test) {
        $test->pushes[] = $branch;

        return $test->pushedSha ?? $test->sha;
    });
    app()->instance(GitRemote::class, $remote);
}

function pullRequestTask(Project $project, array $attributes = []): Task
{
    $task = Task::factory()->for($project)->create([
        'title' => '#12 Fix login', 'stage' => TaskStage::Review,
        'pull_request_number' => 12, 'pull_request_branch' => 'fix-login', 'pull_request_base' => 'main',
        'pull_request_head_sha' => str_repeat('a', 40), ...$attributes,
    ]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-1', 'status' => SandboxStatus::Running]);

    return $task;
}

test('without a GitHub repository the section says to connect one', function () {
    $this->project->update(['git_remote_url' => 'https://gitlab.com/acme/app.git']);

    $this->getJson(route('projects.pulls.index', $this->project))
        ->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('pulls', []);
})->group('GIT-013');

test('the open pull requests are listed with their checks, review, comments and task', function () {
    ($this->github)();
    $task = pullRequestTask($this->project);

    $this->getJson(route('projects.pulls.index', $this->project))
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('repository', 'acme/app')
        ->assertJsonPath('pulls.0.number', 12)
        ->assertJsonPath('pulls.0.state', 'open')
        ->assertJsonPath('pulls.0.checks', 'failure')
        ->assertJsonPath('pulls.0.review', 'changes_requested')
        ->assertJsonPath('pulls.0.comments', 3)
        ->assertJsonPath('pulls.0.author.login', 'sam')
        ->assertJsonPath('pulls.0.fork', false)
        ->assertJsonPath('pulls.1.state', 'draft')
        ->assertJsonPath('pulls.1.fork', true)
        ->assertJsonPath('pulls.1.checks', null)
        ->assertJsonPath('tasks.12', $task->id);

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.github.com/graphql' && $request['variables']['states'] === ['OPEN']);
})->group('GIT-013');

test('closed pull requests are asked for as closed and merged', function () {
    ($this->github)();

    $this->getJson(route('projects.pulls.index', [$this->project, 'closed' => 1]))->assertOk();

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.github.com/graphql' && $request['variables']['states'] === ['CLOSED', 'MERGED']);
})->group('GIT-013');

test("a pull request's page has its details, conversation oldest first, and checks with failures first", function () {
    ($this->github)();

    $response = $this->getJson(route('projects.pulls.show', [$this->project, 12]))
        ->assertOk()
        ->assertJsonPath('pull.title', 'Fix login')
        ->assertJsonPath('pull.head', 'fix-login')
        ->assertJsonPath('pull.base', 'main')
        ->assertJsonPath('pull.fork', false)
        ->assertJsonPath('pull.mergeable', true)
        ->assertJsonPath('pull.comments', 2)
        ->assertJsonPath('checks.0.name', 'tests')
        ->assertJsonPath('checks.0.state', 'failure')
        ->assertJsonPath('checks.0.actions', true)
        ->assertJsonPath('checks_state', 'failure')
        ->assertJsonPath('task', null)
        ->assertJsonPath('check_out_problem', null);

    // The pending review (not submitted yet) is left out.
    expect(collect($response->json('conversation'))->map(fn (array $entry) => [$entry['kind'], $entry['body']])->all())->toBe([
        ['review', 'Needs a test.'],
        ['comment', 'Looks close.'],
        ['line', 'Typo here.'],
    ])->and($response->json('conversation.0.state'))->toBe('changes_requested')
        ->and($response->json('conversation.2.path'))->toBe('app/Login.php');
})->group('GIT-013');

test('the files changed mark binary files and diffs GitHub left out', function () {
    ($this->github)();

    $files = $this->getJson(route('projects.pulls.files', [$this->project, 12]))->assertOk()->json('files');

    expect($files[0])->toMatchArray(['path' => 'app/Login.php', 'additions' => 2, 'deletions' => 1, 'binary' => false, 'truncated' => false])
        ->and($files[1])->toMatchArray(['path' => 'logo.png', 'binary' => true, 'truncated' => false])
        ->and($files[2])->toMatchArray(['path' => 'package-lock.json', 'binary' => false, 'truncated' => true, 'patch' => '']);
})->group('GIT-013');

test('the commits and the end of a failed check\'s log, without timestamps or colors', function () {
    ($this->github)();

    $this->getJson(route('projects.pulls.commits', [$this->project, 12]))
        ->assertOk()
        ->assertJsonPath('commits.0.author', 'sam')
        ->assertJsonPath('commits.0.message', "Fix login\n\nDetails");

    $this->getJson(route('projects.pulls.log', [$this->project, 12, 'run-501']))
        ->assertOk()
        ->assertJsonPath('log', "FAILED Tests\\LoginTest > it logs in\nExpected 200, got 500");
})->group('GIT-013');

test('commit statuses from other CIs are checks too, and all passing reads as success', function () {
    $this->checkRuns = [];
    $this->statuses = [['id' => 7, 'context' => 'ci/circle', 'state' => 'success', 'target_url' => 'https://circle.test/7', 'description' => 'Passed', 'created_at' => null, 'updated_at' => null]];
    ($this->github)();

    $this->getJson(route('projects.pulls.checks', [$this->project, 12]))
        ->assertOk()
        ->assertJsonPath('checks.0.name', 'ci/circle')
        ->assertJsonPath('checks.0.url', 'https://circle.test/7')
        ->assertJsonPath('checks_state', 'success');
})->group('GIT-013');

test("GitHub's refusals are explained", function () {
    ($this->github)(['api.github.com/repos/acme/app/pulls/12' => Http::response(['message' => 'Resource not accessible by integration'], 403)]);

    $this->getJson(route('projects.pulls.show', [$this->project, 12]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'GitHub refused to show this: the app or token needs the Pull requests: Read permission. (Resource not accessible by integration)');
})->group('GIT-013');

test('a GitHub App repository is read with a fresh installation token', function () {
    $this->project->update(['git_remote_token' => null, 'github_installation_id' => 777]);
    $this->mock(GitHubApp::class, fn ($mock) => $mock->shouldReceive('installationToken')->with(777)->andReturn('ghp_token'));
    ($this->github)();

    $this->getJson(route('projects.pulls.index', $this->project))->assertOk()->assertJsonPath('pulls.0.number', 12);
})->group('GIT-013');

test('only people who can see the project see its pull requests, and only those who can change it check them out', function () {
    ($this->github)();
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create());

    $this->getJson(route('projects.pulls.index', $this->project))->assertForbidden();
    $this->getJson(route('projects.pulls.show', [$this->project, 12]))->assertForbidden();
    $this->postJson(route('projects.pulls.check-out', [$this->project, 12]))->assertForbidden();
    $this->postJson(route('projects.pulls.fix', [$this->project, 12]))->assertForbidden();
})->group('GIT-013', 'GIT-014');

test('checking out starts a task on the pull request, making its copy without running the agent', function () {
    ($this->github)();
    Bus::fake();

    $url = $this->postJson(route('projects.pulls.check-out', [$this->project, 12]))->assertOk()->json('url');

    $task = Task::sole();
    expect($url)->toBe(route('projects.tasks.show', [$this->project, $task]))
        ->and($task->title)->toBe('#12 Fix login')
        ->and($task->stage)->toBe(TaskStage::Review)
        ->and($task->status)->toBe(ProjectStatus::Working)
        ->and($task->only('pull_request_number', 'pull_request_branch', 'pull_request_base', 'pull_request_fork', 'pull_request_push', 'pull_request_autofix'))
        ->toBe(['pull_request_number' => 12, 'pull_request_branch' => 'fix-login', 'pull_request_base' => 'main', 'pull_request_fork' => false, 'pull_request_push' => true, 'pull_request_autofix' => false])
        ->and($task->sandbox->status)->toBe(SandboxStatus::Creating);
    Bus::assertDispatched(ForkTaskSandbox::class, fn (ForkTaskSandbox $job) => $job->task->is($task) && $job->checkOutOnly);
    Bus::assertNotDispatched(RunAgentTask::class);

    // Checked out once: again goes to its task.
    $this->postJson(route('projects.pulls.check-out', [$this->project, 12]))->assertOk()->assertJsonPath('url', $url);
    expect(Task::count())->toBe(1);

    $this->getJson(route('projects.pulls.show', [$this->project, 12]))->assertJsonPath('task.id', $task->id);
})->group('GIT-014');

test('checking out says why it can\'t: closed pull requests, or no task copies', function () {
    $this->pull = [...$this->pull, 'state' => 'closed', 'merged' => true];
    ($this->github)();

    $this->postJson(route('projects.pulls.check-out', [$this->project, 12]))->assertStatus(422)->assertJsonPath('message', 'Only open pull requests can be checked out.');
    $this->getJson(route('projects.pulls.show', [$this->project, 12]))->assertJsonPath('pull.state', 'merged')->assertJsonPath('check_out_problem', 'Only open pull requests can be checked out.');

    config(['sandbox.task_copies' => false]);
    $this->pull = [...$this->pull, 'state' => 'open', 'merged' => false];
    cache()->flush();

    $this->postJson(route('projects.pulls.check-out', [$this->project, 12]))->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'SANDBOX_TASK_COPIES'));
    expect(Task::count())->toBe(0);
})->group('GIT-014');

test("the task's copy is switched to the pull request's branch at its newest commit, then goes idle", function () {
    // Making the copy itself runs (CreateSandbox, synchronously).
    Queue::fake([WatchPullRequestChecks::class, RunAgentTask::class]);
    fakePullRequestRemote($this);
    $task = Task::factory()->for($this->project)->create(['status' => ProjectStatus::Working, 'sync_status' => TaskSyncStatus::Forking, 'pull_request_number' => 12, 'pull_request_branch' => 'fix-login', 'pull_request_base' => 'main']);
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);
    // Sent while the copy was being made: it waits, then runs.
    app(AgentQueue::class)->send($task, 'Explain this change', now: true);

    (new ForkTaskSandbox($task, checkOutOnly: true))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $copy = $task->sandbox()->first();
    $commands = collect($this->provider->executed)->where('id', $copy->external_id)->pluck('command');
    $checkout = $commands->first(fn (array $command) => $command[0] === '/opt/onedrop/fork' && $command[1] === 'checkout');

    expect($checkout[3])->toBe('fix-login')
        ->and($checkout[2])->toEndWith('/pull.bundle')
        ->and($commands->contains(fn (array $command) => $command[0] === '/opt/onedrop/fork' && $command[1] === 'branch'))->toBeFalse()
        ->and($task->fresh()->pull_request_head_sha)->toBe($this->sha)
        ->and($task->fresh()->sync_status)->toBeNull()
        ->and($task->messages()->where('role', MessageRole::Activity)->pluck('content')->all())->toContain('Checked out #12 at aaaaaaa')
        // The message sent meanwhile runs now.
        ->and($task->messages()->where('role', MessageRole::User)->pluck('content')->all())->toBe(['Explain this change']);
    Queue::assertPushed(WatchPullRequestChecks::class, fn (WatchPullRequestChecks $job) => $job->sha === $this->sha);
    Queue::assertPushed(RunAgentTask::class);
})->group('GIT-014');

test("a GitHub App repository's pull request is checked out fresh: the new sandbox fetches it itself, nothing comes from Main", function () {
    Queue::fake([WatchPullRequestChecks::class, RunAgentTask::class]);
    $this->project->update(['github_installation_id' => 77, 'git_remote_token' => null]);
    $remote = Mockery::mock(GitRemote::class);
    $remote->shouldReceive('fetchesInSandbox')->andReturn(true);
    $remote->shouldReceive('checkOutPullRequestInSandbox')->once()
        ->withArgs(fn (Project $project, Sandbox $sandbox, int $number, string $branch, string $base) => $sandbox->task_id !== null && [$number, $branch, $base] === [12, 'fix-login', 'main'])
        ->andReturn($this->sha);
    app()->instance(GitRemote::class, $remote);
    $task = Task::factory()->for($this->project)->create(['status' => ProjectStatus::Working, 'sync_status' => TaskSyncStatus::Forking, 'pull_request_number' => 12, 'pull_request_branch' => 'fix-login', 'pull_request_base' => 'main']);
    $task->sandbox()->create(['provider' => 'fake', 'status' => SandboxStatus::Creating]);

    (new ForkTaskSandbox($task, checkOutOnly: true))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $copy = $task->sandbox()->first();
    expect($copy->status)->toBe(SandboxStatus::Running)
        // Main isn't snapshotted or copied.
        ->and(collect($this->provider->executed)->where('id', 'main-1')->pluck('command')->contains(fn (array $command) => in_array($command[1] ?? null, ['snapshot', 'put'], true)))->toBeFalse()
        ->and($this->provider->copied)->toBeEmpty()
        // Its app starts, and sets itself up.
        ->and(collect($this->provider->executed)->where('id', $copy->external_id)->pluck('command')->all())->toContain(['/opt/onedrop/restart'])
        ->and($task->fresh()->only('pull_request_head_sha', 'sync_status', 'status'))->toBe(['pull_request_head_sha' => $this->sha, 'sync_status' => null, 'status' => ProjectStatus::Idle])
        ->and($task->messages()->pluck('content')->all())->toContain('Checking out #12 (fix-login) in a fresh sandbox for this task', 'Checked out #12 at aaaaaaa');
})->group('GIT-014');

test('the sandbox fetches the pull request with a token only in its environment, and says why when it fails', function () {
    Sleep::fake();
    $this->project->update(['github_installation_id' => 77, 'git_remote_token' => null]);
    $github = Mockery::mock(GitHubApp::class);
    $github->shouldReceive('repositoryReadToken')->with(77, 'acme/app', true)->andReturn('ghs_read');
    app()->instance(GitHubApp::class, $github);
    $sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'copy-9', 'status' => SandboxStatus::Running]);
    $state = 'done';
    $this->provider->execUsing = function (array $command) use (&$state) {
        return match (true) {
            ($command[4] ?? null) === '/tmp/onedrop-checkout' && str_contains($command[2] ?? '', 'state=$(cat') => new ExecResult(0, $state === 'done' ? "done\nfix-login\n0\n" : "failed\nfix-login\n128\nfatal: couldn't find remote ref refs/pull/12/head"),
            $command === ['cat', '/tmp/onedrop-checkout/sha'] => new ExecResult(0, str_repeat('a', 40)."\n"),
            default => new ExecResult(0, ''),
        };
    };

    expect(app(GitRemote::class)->checkOutPullRequestInSandbox($this->project, $sandbox, 12, 'fix-login', 'main'))->toBe(str_repeat('a', 40));

    $run = collect($this->provider->executed)->first(fn (array $exec) => $exec['detach'] ?? false);
    expect($run['env'])->toMatchArray(['ONEDROP_FETCH_REFS' => '+refs/pull/12/head:refs/onedrop/pull +refs/heads/main:refs/remotes/origin/main', 'ONEDROP_CHECKOUT_REF' => 'refs/onedrop/pull', 'ONEDROP_CHECKOUT_BRANCH' => 'fix-login', 'ONEDROP_FETCH_AUTH' => 'Authorization: Basic '.base64_encode('x-access-token:ghs_read')])
        ->and(implode(' ', $run['command']))->not->toContain('ghs_read');

    $state = 'failed';
    expect(fn () => app(GitRemote::class)->checkOutPullRequestInSandbox($this->project, $sandbox, 12, 'fix-login', 'main'))->toThrow(GitException::class);

    // A sandbox that stops answering (its task deleted, say) ends the wait at once, rather than holding the queue's worker.
    $this->provider->execUsing = fn (array $command) => ($command[4] ?? null) === '/tmp/onedrop-checkout' && str_contains($command[2] ?? '', 'state=$(cat')
        ? new ExecResult(1, '', 'Error response from daemon: No such container')
        : new ExecResult(0, '');
    expect(fn () => app(GitRemote::class)->checkOutPullRequestInSandbox($this->project, $sandbox, 12, 'fix-login', 'main'))
        ->toThrow(GitException::class, 'The sandbox stopped answering during the download. Try again.');
    Sleep::assertSleptTimes(0);
})->group('GIT-014');

test('pushing commits the copy and pushes its branch to the pull request, then watches the checks', function () {
    Queue::fake();
    fakePullRequestRemote($this);
    $this->pushedSha = str_repeat('b', 40);
    $task = pullRequestTask($this->project);

    $this->post(route('projects.tasks.pull-request.push', [$this->project, $task]))->assertRedirect();
    expect($task->fresh()->sync_status)->toBe(TaskSyncStatus::Pushing);
    (new SyncPullRequestTask($task->fresh(), TaskSyncStatus::Pushing))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $task->refresh();
    expect($this->pushes)->toBe(['fix-login'])
        ->and(collect($this->provider->executed)->where('id', 'copy-1')->pluck('command')->contains(fn (array $command) => str_contains($command[2] ?? '', 'fork bundle')))->toBeTrue()
        ->and($task->only('sync_status', 'sync_error', 'pull_request_head_sha', 'pull_request_checks'))->toBe(['sync_status' => null, 'sync_error' => null, 'pull_request_head_sha' => $this->pushedSha, 'pull_request_checks' => 'pending'])
        ->and($task->messages()->pluck('content')->last())->toBe('Pushed to #12 (bbbbbbb)');
    Queue::assertPushed(WatchPullRequestChecks::class, fn (WatchPullRequestChecks $job) => $job->sha === $this->pushedSha);
})->group('GIT-014', 'GIT-015');

test('a push with nothing new says nothing, and a refused push says why', function () {
    Queue::fake();
    fakePullRequestRemote($this);
    $task = pullRequestTask($this->project);

    (new SyncPullRequestTask($task, TaskSyncStatus::Pushing))->handle(app(TaskCopies::class), app(AgentQueue::class));
    expect($task->messages()->count())->toBe(0);
    Queue::assertNotPushed(WatchPullRequestChecks::class);

    $remote = Mockery::mock(GitRemote::class);
    $remote->shouldReceive('pushBundle')->andThrow(new GitException('The remote has commits this project doesn\'t. Pull first.'));
    app()->instance(GitRemote::class, $remote);

    (new SyncPullRequestTask($task, TaskSyncStatus::Pushing))->handle(app(TaskCopies::class), app(AgentQueue::class));
    expect($task->fresh()->sync_error)->toBe('The remote has commits this project doesn\'t. Pull first.')
        ->and($task->messages()->pluck('content')->last())->toBe('Couldn\'t push to #12: The remote has commits this project doesn\'t. Pull first.');
})->group('GIT-014');

test("a fork's pull request can't be pushed to", function () {
    $task = pullRequestTask($this->project, ['pull_request_fork' => true]);

    $this->post(route('projects.tasks.pull-request.push', [$this->project, $task]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'This pull request comes from a fork, so OneDrop can\'t push to it.');
    expect($task->fresh()->sync_status)->toBeNull();
})->group('GIT-014');

test("pulling merges the pull request's new commits and hands follow-up to the agent; with none it says so", function () {
    Queue::fake();
    fakePullRequestRemote($this);
    $task = pullRequestTask($this->project);

    (new SyncPullRequestTask($task, TaskSyncStatus::Pulling))->handle(app(TaskCopies::class), app(AgentQueue::class));
    expect($task->messages()->pluck('content')->all())->toBe(['Already up to date with #12']);

    $this->remoteSha = str_repeat('c', 40);
    fakePullRequestRemote($this);
    $this->mergeResult = new ExecResult(3, "app/Login.php\n");

    (new SyncPullRequestTask($task->fresh(), TaskSyncStatus::Pulling))->handle(app(TaskCopies::class), app(AgentQueue::class));

    $task->refresh();
    expect($task->pull_request_head_sha)->toBe($this->remoteSha)
        ->and($task->messages()->where('role', MessageRole::Activity)->pluck('content')->last())->toBe('Brought in the latest from #12, with conflicts')
        ->and($task->messages()->where('role', MessageRole::User)->value('content'))->toContain('Commits pushed to pull request #12')->toContain('app/Login.php');
})->group('GIT-014');

test("a pull request's task can't be applied to Main or updated from it", function () {
    $task = pullRequestTask($this->project);

    $this->post(route('projects.tasks.apply', [$this->project, $task]))->assertInertiaFlash('toast.message', 'A pull request\'s work goes back through GitHub: push it, or pull its newer commits.');
    expect($task->fresh()->sync_status)->toBeNull();
})->group('GIT-014');

test("the agent's work is pushed after each turn unless that's turned off", function () {
    Queue::fake();
    $task = pullRequestTask($this->project, ['status' => ProjectStatus::Working]);
    $token = $task->issueEventsToken();
    $exit = ['events' => [['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '']]];

    $this->withToken($token)->postJson(route('sandbox-events.tasks.store', [$this->main, $task]), $exit)->assertOk();
    Queue::assertPushed(SyncPullRequestTask::class, fn (SyncPullRequestTask $job) => $job->direction === TaskSyncStatus::Pushing);

    Queue::fake();
    $task->update(['status' => ProjectStatus::Working, 'sync_status' => null, 'pull_request_push' => false]);
    $this->withToken($task->issueEventsToken())->postJson(route('sandbox-events.tasks.store', [$this->main, $task]), $exit)->assertOk();
    Queue::assertNotPushed(SyncPullRequestTask::class);
})->group('GIT-014');

test('the switches are only for a pull request\'s task', function () {
    $task = pullRequestTask($this->project);
    $plain = Task::factory()->for($this->project)->create();

    $this->patch(route('projects.tasks.update', [$this->project, $task]), ['pull_request_push' => false])->assertRedirect();
    $this->patch(route('projects.tasks.update', [$this->project, $plain]), ['pull_request_autofix' => true])->assertRedirect();

    expect($task->fresh()->pull_request_push)->toBeFalse()
        ->and($plain->fresh()->pull_request_autofix)->toBeFalse();
})->group('GIT-014', 'GIT-015');

test('watching the checks says when they pass, and starts the count of fixes over', function () {
    Queue::fake();
    $this->checkRuns[0]['conclusion'] = 'success';
    ($this->github)();
    $task = pullRequestTask($this->project, ['pull_request_fix_attempts' => 2, 'pull_request_checks' => 'pending']);

    (new WatchPullRequestChecks($task, $this->sha))->handle(app(GitHubPulls::class), app(PullRequestFixes::class));

    expect($task->fresh()->only('pull_request_checks', 'pull_request_fix_attempts'))->toBe(['pull_request_checks' => 'success', 'pull_request_fix_attempts' => 0])
        ->and($task->messages()->pluck('content')->all())->toBe(['Checks passed on aaaaaaa']);
})->group('GIT-015');

test('running checks are looked at again a minute later, and a newer push ends the watch', function () {
    Queue::fake();
    $this->checkRuns[0] = [...$this->checkRuns[0], 'status' => 'in_progress', 'conclusion' => null];
    ($this->github)();
    $task = pullRequestTask($this->project);

    (new WatchPullRequestChecks($task, $this->sha, polls: 3))->handle(app(GitHubPulls::class), app(PullRequestFixes::class));
    expect($task->fresh()->pull_request_checks)->toBe('pending');
    Queue::assertPushed(WatchPullRequestChecks::class, fn (WatchPullRequestChecks $job) => $job->polls === 4 && $job->delay !== null);

    Queue::fake();
    (new WatchPullRequestChecks($task, str_repeat('0', 40)))->handle(app(GitHubPulls::class), app(PullRequestFixes::class));
    Queue::assertNothingPushed();
})->group('GIT-015');

test('failed checks are reported, and with automatic fixing sent to the agent with the end of their logs', function () {
    Queue::fake();
    ($this->github)();
    $task = pullRequestTask($this->project);

    (new WatchPullRequestChecks($task, $this->sha))->handle(app(GitHubPulls::class), app(PullRequestFixes::class));
    expect($task->fresh()->pull_request_checks)->toBe('failure')
        ->and($task->messages()->pluck('content')->all())->toBe(['1 check failed on aaaaaaa']);

    $task->update(['pull_request_autofix' => true]);
    (new WatchPullRequestChecks($task->fresh(), $this->sha))->handle(app(GitHubPulls::class), app(PullRequestFixes::class));

    $prompt = $task->messages()->where('role', MessageRole::User)->value('content');
    expect($task->fresh()->pull_request_fix_attempts)->toBe(1)
        ->and($prompt)->toContain('pull request #12 (fix-login into main) failed on commit aaaaaaa')
        ->toContain('### tests (failure)')
        ->toContain('Expected 200, got 500')
        ->not->toContain('lint')
        ->toContain('don\'t skip, delete or loosen tests')
        ->toContain('pushed to the pull request when this turn ends');
    Queue::assertPushed(RunAgentTask::class);
})->group('GIT-015');

test('automatic fixing stops after three tries in a row that fail', function () {
    Queue::fake();
    ($this->github)();
    $task = pullRequestTask($this->project, ['pull_request_autofix' => true, 'pull_request_fix_attempts' => PullRequestFixes::MAX_ATTEMPTS]);

    (new WatchPullRequestChecks($task, $this->sha))->handle(app(GitHubPulls::class), app(PullRequestFixes::class));

    expect($task->messages()->pluck('content')->last())->toBe('Stopped fixing the checks automatically after 3 tries that didn\'t make them pass')
        ->and($task->messages()->where('role', MessageRole::User)->exists())->toBeFalse();
})->group('GIT-015');

test('the end of the logs sent to the agent is capped', function () {
    $this->log = str_repeat('x', 20000).'END';
    $this->checkRuns = collect(range(1, 5))->map(fn (int $i) => [...$this->checkRuns[0], 'name' => "job {$i}"])->all();
    ($this->github)();
    $task = pullRequestTask($this->project);

    $prompt = app(PullRequestFixes::class)->prompt($task, GitHubPulls::failed(app(GitHubPulls::class)->checks($this->project, $this->sha)), $this->sha);

    expect(substr_count($prompt, 'x'))->toBeLessThanOrEqual(GitHubPulls::LOGS_CHARACTERS)
        ->and(substr_count($prompt, '### job'))->toBe(5)
        ->and($prompt)->toContain('END');
})->group('GIT-015');

test('"Fix failing checks" checks the pull request out and sends the failures, or says nothing failed', function () {
    ($this->github)();
    Bus::fake();

    $url = $this->postJson(route('projects.pulls.fix', [$this->project, 12]))->assertOk()->json('url');

    $task = Task::sole();
    expect($url)->toBe(route('projects.tasks.show', [$this->project, $task]))
        ->and($task->pull_request_branch)->toBe('fix-login')
        ->and($task->messages()->where('role', MessageRole::User)->value('content'))->toContain('### tests (failure)');
    // Its first message makes the copy on the pull request's branch, then runs.
    Bus::assertChained([ForkTaskSandbox::class, RunAgentTask::class]);

    $this->checkRuns[0]['conclusion'] = 'success';
    cache()->flush();
    $this->postJson(route('projects.pulls.fix', [$this->project, 12]))->assertStatus(422)->assertJsonPath('message', 'No checks have failed on its newest commit.');
})->group('GIT-015');

test('turning automatic fixing on while the checks are failing starts fixing them', function () {
    Queue::fake();
    ($this->github)();
    $task = pullRequestTask($this->project, ['pull_request_checks' => 'failure', 'pull_request_fix_attempts' => 3]);

    $this->patch(route('projects.tasks.update', [$this->project, $task]), ['pull_request_autofix' => true])->assertRedirect();

    expect($task->fresh()->only('pull_request_autofix', 'pull_request_fix_attempts'))->toBe(['pull_request_autofix' => true, 'pull_request_fix_attempts' => 1])
        ->and($task->messages()->where('role', MessageRole::User)->value('content'))->toContain('### tests (failure)');
})->group('GIT-015');

test("the workspace and the board show a pull request's task with its checks", function () {
    Queue::fake();
    $task = pullRequestTask($this->project, ['pull_request_checks' => 'failure', 'pull_request_autofix' => true]);

    config(['inertia.ssr.enabled' => false]);

    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page
            ->where('task.pull_request.number', 12)
            ->where('task.pull_request.branch', 'fix-login')
            ->where('task.pull_request.checks', 'failure')
            ->where('task.pull_request.autofix', true)
            ->where('task.pull_request.url', 'https://github.com/acme/app/pull/12'));

    $this->get(route('projects.board', $this->project))
        ->assertInertia(fn ($page) => $page->where('tasks.0.pull_request', ['number' => 12, 'checks' => 'failure', 'state' => null]));
})->group('GIT-014', 'GIT-015');

test('a missing Pull requests permission is named when GitHub refuses the list', function () {
    ($this->github)(['api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequests' => null]], 'errors' => [['type' => 'FORBIDDEN', 'message' => 'Resource not accessible by integration']]])]);

    $this->getJson(route('projects.pulls.index', $this->project))
        ->assertStatus(422)
        ->assertJsonPath('message', 'GitHub refused to show this: the app or token needs the Pull requests: Read permission. (Resource not accessible by integration)');
})->group('GIT-013');

/**
 * GitHub's pull request list for acme/app with $pulls (GraphQL nodes) as the open ones.
 *
 * @param  list<array<string, mixed>>  $pulls
 */
function openPullRequests(array $pulls): array
{
    return ['data' => ['repository' => ['pullRequests' => ['nodes' => array_map(fn (array $pull) => [
        'title' => 'A pull request', 'state' => 'OPEN', 'isDraft' => false, 'url' => "https://github.com/acme/app/pull/{$pull['number']}",
        'updatedAt' => '2026-11-23T10:00:00Z', 'baseRefName' => 'main', 'reviewDecision' => null, 'author' => null,
        'headRepository' => ['nameWithOwner' => 'acme/app'], 'comments' => ['totalCount' => 0], 'commits' => ['nodes' => []],
        ...$pull,
    ], $pulls)]]]];
}

/**
 * A task with its own running copy of the app, opening a pull request from it when $branch is given (GIT-024).
 */
function copiedTask(Project $project, ?string $branch = null, array $attributes = []): Task
{
    $task = Task::factory()->for($project)->create([
        'title' => 'Add dark mode', 'description' => 'A toggle in the header.', 'stage' => TaskStage::InProgress,
        'pull_request_branch' => $branch, 'pull_request_head_sha' => $branch ? str_repeat('c', 40) : null, ...$attributes,
    ]);
    $task->sandbox()->create(['project_id' => $project->id, 'provider' => 'fake', 'external_id' => 'copy-2', 'status' => SandboxStatus::Running]);

    return $task;
}

test('the Pull requests page is for people who can see the project, and the sidebar lists it with a GitHub repository', function () {
    config(['inertia.ssr.enabled' => false]);

    $this->get(route('projects.pull-requests', $this->project))
        ->assertInertia(fn ($page) => $page->component('projects/pull-requests')->where('project.id', $this->project->id)->where('project.name', $this->project->name));

    $this->withUnencryptedCookie('open_project', (string) $this->project->id)
        ->get(route('projects.pull-requests', $this->project))
        ->assertInertia(fn ($page) => $page->where('openProject.pull_requests', true)->where('sidebarProjects.recent.0.pull_requests', true));

    $this->project->update(['git_remote_url' => 'https://gitlab.com/acme/app.git']);
    $this->withUnencryptedCookie('open_project', (string) $this->project->id)
        ->get(route('projects.board', $this->project))
        ->assertInertia(fn ($page) => $page->where('openProject.pull_requests', false)->where('sidebarProjects.recent.0.pull_requests', false));

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())->get(route('projects.pull-requests', $this->project))->assertForbidden();
})->group('GIT-022');

test("listing the open pull requests moves a merged pull request's task to Done once, and leaves it where the user puts it", function () {
    Queue::fake();
    $this->pull = [...$this->pull, 'state' => 'closed', 'merged' => true];
    ($this->github)(['api.github.com/graphql' => Http::response(openPullRequests([]))]);
    $task = pullRequestTask($this->project, ['stage' => TaskStage::Review]);

    $this->getJson(route('projects.pulls.index', $this->project))->assertOk();

    expect($task->fresh()->only('stage', 'pull_request_state'))->toBe(['stage' => TaskStage::Done, 'pull_request_state' => 'merged'])
        ->and($task->messages()->pluck('content')->last())->toBe('#12 was merged on GitHub');

    // Moved back by the user: it isn't looked at, or moved, again.
    $task->refresh()->update(['stage' => TaskStage::Review]);
    $this->getJson(route('projects.pulls.index', $this->project))->assertOk();
    expect($task->fresh()->stage)->toBe(TaskStage::Review)
        ->and($task->messages()->count())->toBe(1);

    $this->getJson(route('projects.pulls.index', [$this->project, 'closed' => 1]))->assertOk();
})->group('GIT-023');

test('a closed pull request moves its task to Done, and an open one missing from the newest 50 stays', function () {
    Queue::fake();
    ($this->github)([
        'api.github.com/graphql' => Http::response(openPullRequests([])),
        'api.github.com/repos/acme/app/pulls/12' => Http::response([...$this->pull, 'state' => 'closed']),
        'api.github.com/repos/acme/app/pulls/14' => Http::response([...$this->pull, 'number' => 14]),
    ]);
    $closed = pullRequestTask($this->project);
    $open = pullRequestTask($this->project, ['pull_request_number' => 14]);

    app(PullRequestTasks::class)->refresh($this->project);

    expect($closed->fresh()->only('stage', 'pull_request_state'))->toBe(['stage' => TaskStage::Done, 'pull_request_state' => 'closed'])
        ->and($closed->messages()->pluck('content')->last())->toBe('#12 was closed on GitHub')
        ->and($open->fresh()->only('stage', 'pull_request_state'))->toBe(['stage' => TaskStage::Review, 'pull_request_state' => null]);
})->group('GIT-023');

test("the board and a task's page look at GitHub soon after, at most once a minute, only when a task waits on it", function () {
    Queue::fake();
    config(['inertia.ssr.enabled' => false]);

    $this->get(route('projects.board', $this->project))->assertOk();
    Queue::assertNotPushed(RefreshPullRequestTasks::class);

    $task = pullRequestTask($this->project);
    $this->get(route('projects.board', $this->project))->assertOk();
    $this->get(route('projects.tasks.show', [$this->project, $task]))->assertOk();
    Queue::assertPushed(RefreshPullRequestTasks::class, 1);

    $this->travel(61)->seconds();
    $this->get(route('projects.tasks.show', [$this->project, $task]))->assertOk();
    Queue::assertPushed(RefreshPullRequestTasks::class, 2);
})->group('GIT-023');

test("a merged pull request's task isn't pushed to, by hand or after a turn", function () {
    Queue::fake();
    $task = pullRequestTask($this->project, ['pull_request_state' => 'merged']);

    $this->post(route('projects.tasks.pull-request.push', [$this->project, $task]))->assertRedirect();

    expect($task->fresh()->sync_status)->toBeNull();
    Queue::assertNotPushed(SyncPullRequestTask::class);
})->group('GIT-023');

test("opening a pull request pushes the task's work to a branch of its own and keeps GitHub's page, filled in", function () {
    Queue::fake();
    fakePullRequestRemote($this);
    $this->pushedSha = str_repeat('d', 40);
    ($this->github)(['api.github.com/repos/acme/app' => Http::response(['default_branch' => 'trunk'])]);
    $writer = Mockery::mock(PullRequestWriter::class);
    $writer->shouldReceive('write')->once()->withArgs(fn (Project $project, Sandbox $sandbox, string $branch, string $base) => $sandbox->external_id === 'copy-2' && $base === 'trunk')
        ->andReturn(['title' => 'Add a dark mode toggle', 'body' => "Adds a toggle & remembers it.\n\n- header", 'commits' => 2]);
    app()->instance(PullRequestWriter::class, $writer);
    $task = copiedTask($this->project);

    $this->post(route('projects.tasks.pull-request.open', [$this->project, $task]))->assertRedirect();
    expect($task->fresh()->sync_status)->toBe(TaskSyncStatus::Pushing);
    Queue::assertPushed(OpenTaskPullRequest::class);
    app()->call([new OpenTaskPullRequest($task->fresh()), 'handle']);

    $branch = "onedrop/task-{$task->id}-add-dark-mode";
    $task->refresh();
    expect($this->pushes)->toBe([$branch])
        ->and($task->only('sync_status', 'pull_request_number', 'pull_request_branch', 'pull_request_base', 'pull_request_head_sha'))
        ->toBe(['sync_status' => null, 'pull_request_number' => null, 'pull_request_branch' => $branch, 'pull_request_base' => 'trunk', 'pull_request_head_sha' => $this->pushedSha])
        ->and($task->isOpeningPullRequest())->toBeTrue()
        ->and($task->pull_request_draft['url'])->toBe("https://github.com/acme/app/compare/trunk...onedrop%2Ftask-{$task->id}-add-dark-mode?quick_pull=1&title=Add%20a%20dark%20mode%20toggle&body=Adds%20a%20toggle%20%26%20remembers%20it.%0A%0A-%20header")
        ->and($task->messages()->pluck('content')->last())->toBe("Pushed {$branch} (ddddddd)");
    Queue::assertPushed(LinkTaskPullRequest::class, fn ($job) => $job->branch === $branch);

    // Again: newer work goes to the same branch.
    $writer->shouldReceive('write')->andReturn(['title' => 'Add a dark mode toggle, remembered', 'body' => '', 'commits' => 3]);
    app()->call([new OpenTaskPullRequest($task->fresh()), 'handle']);
    expect($this->pushes)->toBe([$branch, $branch])
        ->and($task->fresh()->pull_request_draft['title'])->toBe('Add a dark mode toggle, remembered');
})->group('GIT-024');

test("the task's own title and notes describe the pull request when the AI can't, and a refused push says why", function () {
    Queue::fake();
    fakePullRequestRemote($this);
    ($this->github)(['api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main'])]);
    $writer = Mockery::mock(PullRequestWriter::class);
    $writer->shouldReceive('write')->andThrow(new GitException('That base branch isn\'t in this project.'));
    app()->instance(PullRequestWriter::class, $writer);
    $task = copiedTask($this->project, attributes: ['sync_status' => TaskSyncStatus::Pushing]);

    app()->call([new OpenTaskPullRequest($task), 'handle']);
    expect($task->fresh()->pull_request_draft)->toMatchArray(['title' => 'Add dark mode', 'body' => 'A toggle in the header.']);

    $remote = Mockery::mock(GitRemote::class);
    $remote->shouldReceive('pushBundle')->andThrow(new GitException('The remote refused the push.'));
    app()->instance(GitRemote::class, $remote);
    $other = copiedTask($this->project, attributes: ['title' => 'Other', 'sync_status' => TaskSyncStatus::Pushing]);
    app()->call([new OpenTaskPullRequest($other), 'handle']);

    expect($other->fresh()->only('sync_status', 'sync_error', 'pull_request_branch'))->toBe(['sync_status' => null, 'sync_error' => 'The remote refused the push.', 'pull_request_branch' => null])
        ->and($other->messages()->pluck('content')->last())->toBe("Couldn't push onedrop/task-{$other->id}-other: The remote refused the push.");
})->group('GIT-024');

test("a pull request can't be opened without a GitHub repository, a running copy, or while the agent works", function () {
    Queue::fake();
    $working = copiedTask($this->project, attributes: ['status' => ProjectStatus::Working]);
    $pull = pullRequestTask($this->project);

    $this->post(route('projects.tasks.pull-request.open', [$this->project, $working]))->assertSessionHas('inertia.flash_data.toast.message', "Wait for the task's agent to finish, or stop it.");
    $this->post(route('projects.tasks.pull-request.open', [$this->project, $pull]))->assertRedirect();

    $working->update(['status' => ProjectStatus::Idle]);
    $working->sandbox()->update(['status' => SandboxStatus::Paused]);
    $this->post(route('projects.tasks.pull-request.open', [$this->project, $working]))->assertRedirect();

    $this->project->update(['git_remote_url' => 'https://gitlab.com/acme/app.git']);
    $working->sandbox()->update(['status' => SandboxStatus::Running]);
    $this->post(route('projects.tasks.pull-request.open', [$this->project, $working]))->assertRedirect();

    Queue::assertNotPushed(OpenTaskPullRequest::class);
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())->post(route('projects.tasks.pull-request.open', [$this->project, $working]))->assertForbidden();
})->group('GIT-024');

test('the task finds the pull request opened from its branch and becomes its task, or looks again a minute later', function () {
    Queue::fake();
    $task = copiedTask($this->project, 'onedrop/task-1-add-dark-mode');
    $found = [];
    ($this->github)(['api.github.com/repos/acme/app/pulls?*' => function (HttpRequest $request) use (&$found) {
        expect($request->data())->toMatchArray(['head' => 'acme:onedrop/task-1-add-dark-mode', 'state' => 'all']);

        return Http::response($found);
    }]);

    app()->call([new LinkTaskPullRequest($task, 'onedrop/task-1-add-dark-mode'), 'handle']);
    expect($task->fresh()->isOpeningPullRequest())->toBeTrue();
    Queue::assertPushed(LinkTaskPullRequest::class, fn ($job) => $job->polls === 1);

    $found = [['number' => 31, 'state' => 'open', 'draft' => false, 'merged_at' => null, 'title' => 'Add dark mode', 'head' => ['sha' => str_repeat('c', 40)], 'base' => ['ref' => 'trunk']]];
    app()->call([new LinkTaskPullRequest($task, 'onedrop/task-1-add-dark-mode', 1), 'handle']);

    $task->refresh();
    expect($task->only('pull_request_number', 'pull_request_base', 'pull_request_draft', 'pull_request_checks', 'stage', 'pull_request_state'))
        ->toBe(['pull_request_number' => 31, 'pull_request_base' => 'trunk', 'pull_request_draft' => null, 'pull_request_checks' => 'pending', 'stage' => TaskStage::Review, 'pull_request_state' => null])
        ->and($task->messages()->pluck('content')->last())->toBe('Opened #31');
    Queue::assertPushed(WatchPullRequestChecks::class, fn ($job) => $job->sha === str_repeat('c', 40));
    Queue::assertPushed(LinkTaskPullRequest::class, 1);
})->group('GIT-024');

test('looking for the pull request stops after half an hour, and listing the open ones links it too', function () {
    Queue::fake();
    $task = copiedTask($this->project, 'onedrop/task-1-add-dark-mode');
    ($this->github)([
        'api.github.com/repos/acme/app/pulls?*' => Http::response([]),
        'api.github.com/graphql' => Http::response(openPullRequests([['number' => 32, 'headRefName' => 'onedrop/task-1-add-dark-mode']])),
    ]);

    app()->call([new LinkTaskPullRequest($task, 'onedrop/task-1-add-dark-mode', LinkTaskPullRequest::MAX_POLLS - 1), 'handle']);
    Queue::assertNotPushed(LinkTaskPullRequest::class);

    $this->getJson(route('projects.pulls.index', $this->project))->assertOk()->assertJsonPath('tasks.32', $task->id);
    expect($task->fresh()->pull_request_number)->toBe(32);
})->group('GIT-024');

test("the task's page offers to open a pull request, then GitHub's page, then shows the pull request's state", function () {
    Queue::fake();
    config(['inertia.ssr.enabled' => false]);
    $task = copiedTask($this->project);

    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page->where('task.can_open_pull_request', true)->where('task.opening_pull_request', null));

    $task->update(['pull_request_branch' => 'onedrop/task-1-x', 'pull_request_base' => 'main', 'pull_request_draft' => ['title' => 'X', 'body' => '', 'url' => 'https://github.com/acme/app/compare/main...x']]);
    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page->where('task.opening_pull_request', ['branch' => 'onedrop/task-1-x', 'base' => 'main', 'url' => 'https://github.com/acme/app/compare/main...x']));

    $task->update(['pull_request_number' => 33, 'pull_request_state' => 'merged']);
    $this->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn ($page) => $page->where('task.can_open_pull_request', false)->where('task.opening_pull_request', null)->where('task.pull_request.state', 'merged'));
})->group('GIT-023', 'GIT-024');
