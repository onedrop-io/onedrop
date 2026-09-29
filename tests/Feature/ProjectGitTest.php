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
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->status = [
        'initialized' => true, 'branch' => 'main', 'branches' => ['main'], 'head' => str_repeat('a', 40),
        'changes' => [['path' => 'index.html', 'status' => 'M']], 'more_changes' => false, 'tracking' => null, 'state' => null,
    ];
    $this->commits = [['sha' => str_repeat('a', 40), 'subject' => 'Build a timer', 'author' => 'OneDrop', 'email' => 'agent@onedrop.io', 'date' => '2026-09-28T10:00:00+00:00', 'agent' => true]];
    $this->requests = [];

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

        $data = $request['op'] === 'log' ? ['commits' => $this->commits, 'more' => true] : $this->status;

        return new ExecResult(0, json_encode(['ok' => true, 'data' => $data]));
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->actingAs($this->user);
});

test('the panel shows the branch, changes and history', function () {
    $this->getJson(route('projects.git.index', $this->project))
        ->assertOk()
        ->assertJsonPath('status.branch', 'main')
        ->assertJsonPath('status.changes.0.path', 'index.html')
        ->assertJsonPath('commits.0.agent', true)
        ->assertJsonPath('remote', null);
})->group('GIT-001');

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

test('changes wait while the agent is working', function (string $route, array $body) {
    $this->project->update(['status' => ProjectStatus::Working]);

    $this->postJson(route($route, $this->project), $body)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Wait for the agent to finish first.');

    expect($this->requests)->toBe([]);
})->with([
    'commit' => ['projects.git.commit', ['message' => 'Hi']],
    'discard' => ['projects.git.discard', ['path' => null]],
    'switch' => ['projects.git.switch', ['branch' => 'main']],
    'restore' => ['projects.git.restore', ['sha' => 'abcdef1']],
])->group('GIT-002');

test('discarding sends one path or everything', function () {
    $this->postJson(route('projects.git.discard', $this->project), ['path' => 'index.html'])->assertOk();
    $this->postJson(route('projects.git.discard', $this->project), ['path' => null])->assertOk();

    expect($this->requests)->toBe([['op' => 'discard', 'path' => 'index.html'], ['op' => 'discard', 'path' => null]]);
})->group('GIT-002');

test('switching branches restarts the app', function () {
    $this->postJson(route('projects.git.switch', $this->project), ['branch' => 'pricing', 'create' => true])->assertOk();

    expect($this->requests[0])->toBe(['op' => 'switch', 'branch' => 'pricing', 'create' => true])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/zap/restart']);
})->group('GIT-002');

test('restoring a version commits it as the user, restarts the app and backs it up', function () {
    $this->postJson(route('projects.git.restore', $this->project), ['sha' => str_repeat('c', 40)])->assertOk();

    expect($this->requests[0])->toBe(['op' => 'restore', 'sha' => str_repeat('c', 40), 'name' => 'Dev User', 'email' => 'dev@example.com'])
        ->and(collect($this->provider->executed)->pluck('command')->all())->toContain(['/opt/zap/restart']);
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
