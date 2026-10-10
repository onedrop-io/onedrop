<?php

use App\Enums\GitSyncStatus;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\BackupProject;
use App\Jobs\SyncGitRemote;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\OneOffPrompt;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->status = [
        'initialized' => true, 'branch' => 'main', 'branches' => ['main'], 'head' => str_repeat('a', 40),
        'staged' => [], 'changes' => [['path' => 'index.html', 'status' => 'M']], 'more_changes' => false, 'tracking' => null, 'state' => null,
    ];
    $this->commits = [['sha' => str_repeat('a', 40), 'subject' => 'Build a timer', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => '2026-09-28T10:00:00+00:00', 'agent' => true]];
    $this->requests = [];
    $this->compared = [
        ['sha' => str_repeat('c', 40), 'subject' => 'Add plans', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => '2026-09-28T11:00:00+00:00', 'agent' => true],
        ['sha' => str_repeat('b', 40), 'subject' => 'Add a pricing page', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => '2026-09-28T10:30:00+00:00', 'agent' => true],
    ];

    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = function (array $command, array $env) {
        if (! isset($env['APP_GIT_REQUEST'])) {
            return new ExecResult(0, '');
        }

        $request = json_decode($env['APP_GIT_REQUEST'], true);
        $this->requests[] = $request;

        if (($request['sha'] ?? null) === str_repeat('b', 40)) {
            return new ExecResult(0, json_encode(['ok' => false, 'error' => "That version isn't in this project's history."]));
        }

        $data = match ($request['op']) {
            'log' => ['commits' => $this->commits, 'more' => true],
            'changes_diff' => ['patch' => "-color: black;\n+color: blue;\n", 'new_files' => [], 'truncated' => false],
            'combine_preview' => ['commits' => $this->compared, 'patch' => "+plans\n", 'truncated' => false],
            'compare' => ['base' => $request['base'], 'commits' => $this->compared, 'more' => false, 'patch' => "+plans\n", 'truncated' => false],
            'change_diff' => ['path' => $request['path'], 'staged' => $request['staged'], 'patch' => "@@ -1 +1 @@\n-a\n+b\n", 'truncated' => false, 'binary' => false, 'files' => null],
            'checkpoints' => ['checkpoints' => [['sha' => str_repeat('d', 40), 'subject' => 'Build a timer', 'date' => '2026-09-28T10:00:00+00:00', 'kind' => 'turn', 'files' => 1, 'additions' => 3, 'deletions' => 0, 'restorable_before' => true]], 'more' => false],
            default => $this->status,
        };

        return new ExecResult(0, json_encode(['ok' => true, 'data' => $data]));
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->actingAs($this->user);
});

test('the panel shows the branch, changes, history, checkpoints and whether the agent commits', function () {
    $this->getJson(route('projects.git.index', $this->project))
        ->assertOk()
        ->assertJsonPath('status.branch', 'main')
        ->assertJsonPath('status.staged', [])
        ->assertJsonPath('status.changes.0.path', 'index.html')
        ->assertJsonPath('commits.0.agent', true)
        ->assertJsonPath('checkpoints.checkpoints.0.kind', 'turn')
        ->assertJsonPath('commit_turns', true)
        ->assertJsonPath('remote', null);
})->group('GIT-001', 'SCM-001');

test('only the project owner can use its git', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create());

    $this->getJson(route('projects.git.index', $this->project))->assertForbidden();
    $this->postJson(route('projects.git.commit', $this->project), ['message' => 'Hi'])->assertForbidden();
})->group('GIT-001');

test('git explains when the sandbox is not running', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->getJson(route('projects.git.index', $this->project))->assertStatus(409);
})->group('GIT-001');

test('committing commits as the signed-in user and backs the project up', function () {
    $this->postJson(route('projects.git.commit', $this->project), ['message' => 'Make the button blue'])
        ->assertOk()
        ->assertJsonPath('commits.0.subject', 'Build a timer');

    expect($this->requests[0])->toBe(['op' => 'commit', 'message' => 'Make the button blue', 'name' => 'Dev User', 'email' => 'dev@example.com']);
    Queue::assertPushed(BackupProject::class);
})->group('GIT-002');

test('a change\'s diff, staged or unstaged, comes from the sandbox, for the owner only', function () {
    $this->getJson(route('projects.git.change-diff', [$this->project, 'path' => 'index.html']))
        ->assertOk()
        ->assertJsonPath('patch', "@@ -1 +1 @@\n-a\n+b\n");

    expect(end($this->requests))->toBe(['op' => 'change_diff', 'path' => 'index.html', 'staged' => false]);

    $this->getJson(route('projects.git.change-diff', [$this->project, 'path' => 'index.html', 'staged' => 1]))->assertJsonPath('staged', true);

    $this->getJson(route('projects.git.change-diff', $this->project))->assertUnprocessable();
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create());
    $this->getJson(route('projects.git.change-diff', [$this->project, 'path' => 'index.html']))->assertForbidden();
})->group('GIT-006');

test('committing on a new branch switches to it first', function () {
    $this->postJson(route('projects.git.commit', $this->project), ['message' => 'Pricing page', 'branch' => 'pricing'])
        ->assertOk()
        ->assertJsonPath('message', 'Pricing page');

    expect(array_slice($this->requests, 0, 2))->toBe([
        ['op' => 'switch', 'branch' => 'pricing', 'create' => true],
        ['op' => 'commit', 'message' => 'Pricing page', 'name' => 'Dev User', 'email' => 'dev@example.com'],
    ]);
})->group('GIT-006');

test('a commit without a message gets one from the project\'s AI', function () {
    $this->mock(OneOffPrompt::class)
        ->shouldReceive('ask')
        ->withArgs(fn (Project $project, string $prompt) => str_contains($prompt, '+color: blue;'))
        ->andReturn("\"Make the button blue\"\n");

    $this->postJson(route('projects.git.commit', $this->project), ['message' => ''])
        ->assertOk()
        ->assertJsonPath('message', 'Make the button blue');

    expect(collect($this->requests)->firstWhere('op', 'commit')['message'])->toBe('Make the button blue');
})->group('GIT-006');

test('a commit without a message names its files when the AI can\'t write one', function () {
    $this->status['changes'] = [['path' => 'src/App.tsx', 'status' => 'M'], ['path' => 'src/new.ts', 'status' => '?'], ['path' => 'README.md', 'status' => 'M']];
    $this->mock(OneOffPrompt::class)->shouldReceive('ask')->andThrow(new SandboxException('No connected AI can be asked.'));

    $this->postJson(route('projects.git.commit', $this->project), [])
        ->assertOk()
        ->assertJsonPath('message', 'Update App.tsx and 2 other files');

    // With files staged, only those are named.
    $this->status['staged'] = [['path' => 'src/new.ts', 'status' => 'A']];
    $this->postJson(route('projects.git.commit', $this->project), [])
        ->assertJsonPath('message', 'Add new.ts');
})->group('GIT-006', 'SCM-001');

test('the message box can ask the project\'s AI for a message', function () {
    $this->mock(OneOffPrompt::class)->shouldReceive('ask')->andReturn('Make the button blue');

    $this->postJson(route('projects.git.draft-message', $this->project))
        ->assertOk()
        ->assertJsonPath('message', 'Make the button blue');

    expect(collect($this->requests)->pluck('op')->all())->not->toContain('commit');
})->group('SCM-001');

test('files are staged and unstaged, all of them or the chosen ones, even while the agent works', function () {
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->postJson(route('projects.git.stage', $this->project), ['paths' => ['index.html']])->assertOk()->assertJsonPath('status.branch', 'main');
    $this->postJson(route('projects.git.stage', $this->project))->assertOk();
    $this->postJson(route('projects.git.unstage', $this->project), ['paths' => ['index.html']])->assertOk();
    $this->postJson(route('projects.git.unstage', $this->project), ['paths' => []])->assertUnprocessable();

    expect($this->requests)->toBe([
        ['op' => 'stage', 'paths' => ['index.html']],
        ['op' => 'stage', 'paths' => null],
        ['op' => 'unstage', 'paths' => ['index.html']],
    ]);
})->group('SCM-001');

test('a hunk or some lines of a file are staged or unstaged against the diff that was shown', function () {
    $hash = str_repeat('f', 40);

    $this->postJson(route('projects.git.stage-lines', $this->project), ['path' => 'index.html', 'hash' => $hash, 'hunk' => 1])->assertOk();
    $this->postJson(route('projects.git.stage-lines', $this->project), ['path' => 'index.html', 'hash' => $hash, 'staged' => true, 'lines' => [7, 8]])->assertOk();
    $this->postJson(route('projects.git.stage-lines', $this->project), ['path' => 'index.html', 'hash' => $hash])->assertUnprocessable();

    expect($this->requests)->toBe([
        ['op' => 'stage_lines', 'path' => 'index.html', 'hash' => $hash, 'staged' => false, 'hunk' => 1, 'lines' => null],
        ['op' => 'stage_lines', 'path' => 'index.html', 'hash' => $hash, 'staged' => true, 'hunk' => null, 'lines' => [7, 8]],
    ]);
})->group('GIT-009', 'SCM-001');

test('the agent\'s checkpoints are listed a page at a time, and one is restored as uncommitted changes', function () {
    $sha = str_repeat('d', 40);

    $this->getJson(route('projects.git.checkpoints', [$this->project, 'offset' => 100]))
        ->assertOk()
        ->assertJsonPath('checkpoints.0.subject', 'Build a timer');

    $this->postJson(route('projects.git.restore-checkpoint', $this->project), ['sha' => $sha, 'before' => true])
        ->assertOk()
        ->assertJsonPath('checkpoints.checkpoints.0.sha', $sha);

    expect(collect($this->requests)->whereIn('op', ['checkpoints', 'restore_checkpoint'])->values()->all())->toBe([
        ['op' => 'checkpoints', 'offset' => 100],
        ['op' => 'restore_checkpoint', 'sha' => $sha, 'before' => true],
        ['op' => 'checkpoints', 'offset' => 0],
    ])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/onedrop/restart']);
    Queue::assertPushed(BackupProject::class);

    $this->project->update(['status' => ProjectStatus::Working]);
    $this->postJson(route('projects.git.restore-checkpoint', $this->project), ['sha' => $sha])->assertStatus(409);
})->group('SCM-002');

test('the owner turns the agent\'s commits after each turn on and off', function () {
    $this->patchJson(route('projects.git.settings', $this->project), ['commit_turns' => false])
        ->assertOk()
        ->assertJsonPath('commit_turns', false);

    expect($this->project->fresh()->commit_turns)->toBeFalse();

    $this->patchJson(route('projects.git.settings', $this->project), [])->assertUnprocessable();
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create());
    $this->patchJson(route('projects.git.settings', $this->project), ['commit_turns' => true])->assertForbidden();
})->group('SCM-003');

test('connecting a repository stops the agent committing each turn, unless turned back on', function () {
    expect($this->project->commit_turns)->toBeTrue();

    $this->putJson(route('projects.git.connect', $this->project), ['url' => 'https://github.com/dev/timer.git', 'token' => 'ghp_x'])->assertOk();

    expect($this->project->fresh()->commit_turns)->toBeFalse();

    $this->project->update(['commit_turns' => true]);
    $this->deleteJson(route('projects.git.disconnect', $this->project))->assertOk();
    $this->putJson(route('projects.git.connect', $this->project), ['url' => 'https://github.com/dev/timer.git', 'token' => 'ghp_x'])->assertOk();

    expect($this->project->fresh()->commit_turns)->toBeFalse()
        ->and(Project::factory()->create(['git_remote_url' => 'https://github.com/dev/imported.git'])->commit_turns)->toBeFalse()
        ->and(Project::factory()->create()->commit_turns)->toBeTrue();
})->group('SCM-003');

test('changes wait while the agent is working', function (string $route, array $body) {
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->postJson(route($route, $this->project), $body)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Wait for the agent to finish first.');

    expect($this->requests)->toBe([]);
})->with([
    'commit' => ['projects.git.commit', ['message' => 'Hi']],
    'discard' => ['projects.git.discard', []],
    'switch' => ['projects.git.switch', ['branch' => 'main']],
    'restore' => ['projects.git.restore', ['sha' => 'abcdef1']],
])->group('GIT-002');

test('discarding sends the chosen paths or everything', function () {
    $this->postJson(route('projects.git.discard', $this->project), ['paths' => ['index.html']])->assertOk();
    $this->postJson(route('projects.git.discard', $this->project))->assertOk();

    expect($this->requests)->toBe([['op' => 'discard', 'paths' => ['index.html']], ['op' => 'discard', 'paths' => null]]);
})->group('GIT-002');

test('switching branches restarts the app', function () {
    $this->postJson(route('projects.git.switch', $this->project), ['branch' => 'pricing', 'create' => true])->assertOk();

    expect($this->requests[0])->toBe(['op' => 'switch', 'branch' => 'pricing', 'create' => true])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/onedrop/restart']);
})->group('GIT-002');

test('restoring a version commits it as the user, restarts the app and backs it up', function () {
    $this->postJson(route('projects.git.restore', $this->project), ['sha' => str_repeat('c', 40)])->assertOk();

    expect($this->requests[0])->toBe(['op' => 'restore', 'sha' => str_repeat('c', 40), 'name' => 'Dev User', 'email' => 'dev@example.com'])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/onedrop/restart']);
    Queue::assertPushed(BackupProject::class);
})->group('GIT-003');

test('the git tool\'s refusals reach the user', function () {
    $this->postJson(route('projects.git.restore', $this->project), ['sha' => str_repeat('b', 40)])
        ->assertStatus(422)
        ->assertJsonPath('message', "That version isn't in this project's history.");

    $this->postJson(route('projects.git.restore', $this->project), ['sha' => 'not-a-sha'])->assertUnprocessable();
})->group('GIT-003');

test('an existing repository is connected with its token kept encrypted and never shown', function () {
    config(['sandbox.git.allow_private_remotes' => true]);

    $response = $this->putJson(route('projects.git.connect', $this->project), [
        'url' => 'https://git.example.com/dev/timer.git',
        'username' => 'dev',
        'token' => 'secret-token',
    ])->assertOk()
        ->assertJsonPath('remote.url', 'https://git.example.com/dev/timer.git')
        ->assertJsonPath('remote.host', 'git.example.com');

    $stored = DB::table('projects')->where('id', $this->project->id)->value('git_remote_token');

    expect($response->content())->not->toContain('secret-token')
        ->and($stored)->not->toContain('secret-token')
        ->and($this->project->fresh()->git_remote_token)->toBe('secret-token')
        ->and($this->getJson(route('projects.git.index', $this->project))->content())->not->toContain('secret-token');
})->group('GIT-004');

test('remotes must be public HTTPS addresses without credentials', function (string $url, string $message) {
    $this->putJson(route('projects.git.connect', $this->project), ['url' => $url, 'token' => 't'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => $message]);
})->with([
    'ssh' => ['ssh://git@github.com/dev/timer.git', 'HTTPS URL'],
    'http' => ['http://github.com/dev/timer.git', 'HTTPS URL'],
    'credentials' => ['https://dev:token@github.com/dev/timer.git', 'without a username or token'],
    'loopback' => ['https://127.0.0.1/dev/timer.git', 'private network'],
    'private network' => ['https://10.0.0.5/dev/timer.git', 'private network'],
])->group('GIT-004');

test('pushing and pulling run in the background, one at a time', function () {
    $this->project->update(['git_remote_url' => 'https://git.example.com/dev/timer.git', 'git_remote_token' => 't']);

    $this->postJson(route('projects.git.push', $this->project))->assertOk()->assertJsonPath('remote.sync_status', 'pushing');
    $this->postJson(route('projects.git.pull', $this->project))->assertStatus(409);

    Queue::assertPushed(SyncGitRemote::class, fn (SyncGitRemote $job) => $job->direction === GitSyncStatus::Pushing);
})->group('GIT-004');

test('pulling waits while the agent is working, and needs a remote', function () {
    $this->postJson(route('projects.git.pull', $this->project))->assertUnprocessable();

    $this->project->update(['git_remote_url' => 'https://git.example.com/dev/timer.git', 'status' => ProjectStatus::Working]);

    $this->postJson(route('projects.git.pull', $this->project))->assertStatus(409);
    Queue::assertNothingPushed();
})->group('GIT-004');

test('a new GitHub repository is created with the user\'s token, connected and pushed to', function () {
    Http::fake(['api.github.com/user/repos' => Http::response(['clone_url' => 'https://github.com/dev/timer.git'], 201)]);

    $this->postJson(route('projects.git.github', $this->project), ['name' => 'timer', 'token' => 'ghp_secret', 'private' => true])
        ->assertOk()
        ->assertJsonPath('remote.url', 'https://github.com/dev/timer.git')
        ->assertJsonPath('remote.sync_status', 'pushing');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_secret') && $request['name'] === 'timer' && $request['private'] === true);
    Queue::assertPushed(SyncGitRemote::class);
    expect($this->project->fresh()->git_remote_token)->toBe('ghp_secret');
})->group('GIT-004');

test('GitHub\'s refusals are explained', function (int $status, string $message) {
    Http::fake(['api.github.com/user/repos' => Http::response(['message' => 'nope'], $status)]);

    $this->postJson(route('projects.git.github', $this->project), ['name' => 'timer', 'token' => 'ghp_x'])
        ->assertUnprocessable()
        ->assertJsonPath('message', $message);

    expect($this->project->fresh()->git_remote_url)->toBeNull();
})->with([
    'bad token' => [401, "GitHub didn't accept the token."],
    'name taken' => [422, 'Your GitHub account already has a repository called timer.'],
])->group('GIT-004');

test('disconnecting forgets the remote and its token', function () {
    $this->project->update(['git_remote_url' => 'https://git.example.com/dev/timer.git', 'git_remote_token' => 't']);

    $this->deleteJson(route('projects.git.disconnect', $this->project))->assertOk()->assertJsonPath('remote', null);

    expect($this->project->fresh()->only(['git_remote_url', 'git_remote_token']))->toBe(['git_remote_url' => null, 'git_remote_token' => null]);
})->group('GIT-004');

test('a commit\'s details and a file\'s diff come from the sandbox', function () {
    $sha = str_repeat('a', 40);

    $this->getJson(route('projects.git.show', [$this->project, $sha]))->assertOk();
    $this->getJson(route('projects.git.diff', [$this->project, $sha, 'path' => 'index.html']))->assertOk();
    $this->getJson(route('projects.git.diff', [$this->project, $sha]))->assertUnprocessable();
    $this->get("/projects/{$this->project->id}/git/commits/not-a-sha")->assertNotFound();

    expect($this->requests)->toBe([['op' => 'show', 'sha' => $sha], ['op' => 'diff', 'sha' => $sha, 'path' => 'index.html']]);
})->group('GIT-001');

test('the history can be searched and paged', function () {
    $this->getJson(route('projects.git.index', $this->project))->assertJsonPath('more', true);

    $this->getJson(route('projects.git.log', [$this->project, 'query' => 'timer', 'offset' => 50]))
        ->assertOk()
        ->assertJsonPath('commits.0.subject', 'Build a timer');

    expect(end($this->requests))->toBe(['op' => 'log', 'query' => 'timer', 'offset' => 50, 'limit' => 50]);
})->group('GIT-001');

test('a pull request gets a title and description from the project\'s AI, and GitHub\'s page for it', function () {
    $this->status['branch'] = 'pricing';
    $this->project->update(['git_remote_url' => 'https://github.com/dev/timer.git', 'git_remote_token' => 'secret-token']);
    $this->mock(OneOffPrompt::class)
        ->shouldReceive('ask')
        ->withArgs(fn (Project $project, string $prompt) => str_contains($prompt, '- Add a pricing page') && str_contains($prompt, '+plans'))
        ->andReturn("Title: Add a pricing page with plans\n\nAdds a pricing page.\n\n- Plans table");

    $this->postJson(route('projects.git.pull-request', $this->project), ['base' => 'main'])
        ->assertOk()
        ->assertJson([
            'title' => 'Add a pricing page with plans',
            'body' => "Adds a pricing page.\n\n- Plans table",
            'commits' => 2,
            'url' => 'https://github.com/dev/timer/compare/main...pricing',
        ]);
})->group('GIT-007');

test('without the AI, a pull request is titled after its branch and lists its commits', function () {
    $this->status['branch'] = 'feature/pricing-page';
    $this->project->update(['git_remote_url' => 'https://github.com/dev/timer.git', 'git_remote_token' => 'secret-token']);
    $this->mock(OneOffPrompt::class)->shouldReceive('ask')->andThrow(new SandboxException('No connected AI can be asked.'));

    $this->postJson(route('projects.git.pull-request', $this->project), ['base' => 'main'])
        ->assertOk()
        ->assertJsonPath('title', 'Pricing page')
        ->assertJsonPath('body', "## Changes\n\n- Add a pricing page\n- Add plans");

    $this->compared = [$this->compared[0]];

    $this->postJson(route('projects.git.pull-request', $this->project), ['base' => 'main'])
        ->assertJsonPath('title', 'Add plans');
})->group('GIT-007');

test('a pull request needs a GitHub repository and a branch other than its base', function () {
    $this->project->update(['git_remote_url' => 'https://git.example.com/dev/timer.git', 'git_remote_token' => 'secret-token']);

    $this->postJson(route('projects.git.pull-request', $this->project), ['base' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Pull requests need a GitHub repository.');

    $this->project->update(['git_remote_url' => 'https://github.com/dev/timer.git']);

    $this->postJson(route('projects.git.pull-request', $this->project), ['base' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Commit on a new branch first, then open a pull request into main.');

    $this->status['branch'] = 'pricing';
    $this->compared = [];

    $this->postJson(route('projects.git.pull-request', $this->project), ['base' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "This branch has no commits that main doesn't have.");
})->group('GIT-007');

test('combining commits comes with a message from the project\'s AI that lists them', function () {
    $this->mock(OneOffPrompt::class)
        ->shouldReceive('ask')
        ->withArgs(fn (Project $project, string $prompt) => str_contains($prompt, "being combined into one:\n<commits>\n- Add plans\n- Add a pricing page"))
        ->andReturn('Add a pricing page with plans');

    $this->postJson(route('projects.git.combine-draft', $this->project))
        ->assertOk()
        ->assertJsonCount(2, 'commits')
        ->assertJsonPath('message', "Add a pricing page with plans\n\n- Add a pricing page\n- Add plans");
})->group('GIT-008');

test('without the AI, the combined message starts with the newest commit\'s', function () {
    $this->mock(OneOffPrompt::class)->shouldReceive('ask')->andThrow(new SandboxException('No connected AI can be asked.'));

    $this->postJson(route('projects.git.combine-draft', $this->project))
        ->assertJsonPath('message', "Add plans\n\n- Add a pricing page\n- Add plans");
})->group('GIT-008');

test('combining commits as the user backs the project up, and waits for the agent and for pushes', function () {
    $this->postJson(route('projects.git.combine', $this->project), ['message' => 'Pricing'])->assertOk();

    expect(collect($this->requests)->firstWhere('op', 'combine'))->toBe(['op' => 'combine', 'message' => 'Pricing', 'name' => 'Dev User', 'email' => 'dev@example.com']);
    Queue::assertPushed(BackupProject::class);

    $this->postJson(route('projects.git.combine', $this->project), [])->assertUnprocessable();

    $this->project->update(['git_remote_url' => 'https://github.com/dev/timer.git', 'git_sync_status' => GitSyncStatus::Pushing]);
    $this->postJson(route('projects.git.combine', $this->project), ['message' => 'Pricing'])->assertStatus(409);

    $this->project->update(['git_sync_status' => null, 'status' => ProjectStatus::Working]);
    $this->postJson(route('projects.git.combine', $this->project), ['message' => 'Pricing'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Wait for the agent to finish first.');
})->group('GIT-008');

test('one hunk of a file is discarded, waiting for the agent', function () {
    $hash = str_repeat('f', 40);

    $this->postJson(route('projects.git.discard-hunk', $this->project), ['path' => 'index.html', 'hash' => $hash, 'hunk' => 1])
        ->assertOk()
        ->assertJsonPath('status.branch', 'main');

    expect(end($this->requests))->toBe(['op' => 'discard_hunk', 'path' => 'index.html', 'hash' => $hash, 'hunk' => 1]);

    $this->project->update(['status' => ProjectStatus::Working]);
    $this->postJson(route('projects.git.discard-hunk', $this->project), ['path' => 'index.html', 'hash' => $hash, 'hunk' => 1])->assertStatus(409);
})->group('GIT-009');

test('the last commit is undone as the user asked, waiting for the agent and for pushes', function () {
    $sha = str_repeat('a', 40);

    $this->postJson(route('projects.git.undo-commit', $this->project), ['sha' => $sha])
        ->assertOk()
        ->assertJsonPath('status.branch', 'main')
        ->assertJsonPath('commits.0.subject', 'Build a timer');

    expect(collect($this->requests)->firstWhere('op', 'undo_commit'))->toBe(['op' => 'undo_commit', 'sha' => $sha]);
    Queue::assertPushed(BackupProject::class);

    $this->postJson(route('projects.git.undo-commit', $this->project), ['sha' => 'not a sha'])->assertUnprocessable();

    $this->project->update(['git_remote_url' => 'https://github.com/dev/timer.git', 'git_sync_status' => GitSyncStatus::Pulling]);
    $this->postJson(route('projects.git.undo-commit', $this->project), ['sha' => $sha])->assertStatus(409);

    $this->project->update(['git_sync_status' => null, 'status' => ProjectStatus::Working]);
    $this->postJson(route('projects.git.undo-commit', $this->project), ['sha' => $sha])->assertStatus(409);
})->group('GIT-010');
