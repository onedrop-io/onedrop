<?php

use App\Enums\GitSyncStatus;
use App\Jobs\SyncGitRemote;
use App\Models\AgentConnection;
use App\Models\GitHubAuthorization;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\GitRemote;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];

    config(['services.github_app' => [
        'id' => '12345', 'slug' => 'onedrop-test', 'client_id' => 'Iv1.client', 'client_secret' => 'app-secret',
        // Written with \n, as in a one-line .env value.
        'private_key' => str_replace("\n", '\n', $pem),
    ]]);

    $this->head = str_repeat('a', 40);
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = fn (array $command, array $env) => new ExecResult(0, json_encode(['ok' => true, 'data' => [
        'initialized' => $this->head !== null, 'branch' => 'main', 'branches' => ['main'], 'head' => $this->head,
        'changes' => [], 'more_changes' => false, 'tracking' => null, 'state' => null, 'commits' => [], 'more' => false,
    ]]));
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Team Timer']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->installation = GitHubInstallation::factory()->for($this->user)->create(['installation_id' => 777, 'account_login' => 'dev', 'account_type' => 'User']);
    $this->organization = GitHubInstallation::factory()->for($this->user)->create(['installation_id' => 888, 'account_login' => 'acme', 'account_type' => 'Organization']);
    GitHubAuthorization::factory()->for($this->user)->create(['github_login' => 'dev', 'access_token' => 'ghu_user']);
    $this->actingAs($this->user);

    $this->repository = fn (string $fullName, array $extra = []) => [
        'full_name' => $fullName, 'name' => explode('/', $fullName)[1], 'private' => true, 'default_branch' => 'main', 'size' => 10,
        'clone_url' => "https://github.com/{$fullName}.git", 'html_url' => "https://github.com/{$fullName}", 'pushed_at' => '2026-09-20T10:00:00Z', ...$extra,
    ];
    $this->permissions = ['contents' => 'write', 'metadata' => 'read', 'administration' => 'write'];

    // GitHub, answering as it would: the user's token sees what both the user and the app can reach.
    $this->github = function (array $routes = []) {
        Http::fake([
            ...$routes,
            'api.github.com/app/installations/*/access_tokens' => fn (HttpRequest $request) => JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->publicKey, 'RS256'))->iss === '12345'
                ? Http::response(['token' => 'ghs_installation'], 201)
                : Http::response(['message' => 'Bad JWT'], 401),
            'api.github.com/app' => fn () => Http::response(['owner' => ['login' => 'onedrop-io', 'type' => 'Organization'], 'permissions' => $this->permissions]),
            'api.github.com/user/installations/777/repositories*' => fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer ghu_user')
                ? Http::response(['repositories' => [
                    ($this->repository)('dev/old-site', ['pushed_at' => '2025-01-01T00:00:00Z']),
                    ($this->repository)('dev/timer'),
                ]])
                : Http::response([], 401),
            'api.github.com/repos/dev/timer/branches*' => Http::response([['name' => 'main'], ['name' => 'redesign']]),
            'api.github.com/repos/dev/timer' => Http::response(($this->repository)('dev/timer', ['default_branch' => 'trunk'])),
            'api.github.com/repos/dev/empty' => Http::response(($this->repository)('dev/empty', ['size' => 0])),
            'api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
    };
});

test('connecting GitHub sends the user to install the app, or with reconnect just to sign in again', function () {
    $response = $this->get(route('projects.git.github-app.install', $this->project));
    $state = session('github_app.state');

    $response->assertRedirect("https://github.com/apps/onedrop-test/installations/new?state={$state}");
    expect(session('github_app.project'))->toBe($this->project->id);

    $response = $this->get(route('projects.git.github-app.install', [$this->project, 'reconnect' => 1]));
    $response->assertRedirect('https://github.com/login/oauth/authorize?client_id=Iv1.client&state='.session('github_app.state'));
})->group('GIT-005');

test('without a GitHub App the install flow does not exist and the panel says so', function () {
    config(['services.github_app.private_key' => null]);

    $this->get(route('projects.git.github-app.install', $this->project))->assertNotFound();
    $this->getJson(route('projects.git.index', $this->project))
        ->assertJsonPath('github.configured', false)
        ->assertJsonPath('github.problems', []);
})->group('GIT-005');

test('the panel lists the user\'s installations, their own account first, and suggests a repository name', function () {
    ($this->github)();

    $this->getJson(route('projects.git.index', $this->project))
        ->assertJsonPath('github.configured', true)
        ->assertJsonPath('github.signed_in', true)
        ->assertJsonPath('github.login', 'dev')
        ->assertJsonPath('github.suggested_name', 'team-timer')
        ->assertJsonPath('github.installations.0.account', 'dev')
        ->assertJsonPath('github.installations.0.can_create', false)
        ->assertJsonPath('github.installations.0.manage_url', 'https://github.com/settings/installations/777')
        ->assertJsonPath('github.installations.1.account', 'acme')
        ->assertJsonPath('github.installations.1.can_create', true)
        ->assertJsonPath('github.problems', []);
})->group('GIT-005');

test('admins are told what the GitHub App setup is missing; others are not', function () {
    $this->permissions = ['emails' => 'read'];
    ($this->github)();

    $this->getJson(route('projects.git.index', $this->project))->assertJsonPath('github.problems', []);

    $this->user->forceFill(['is_admin' => true])->save();

    $problems = $this->getJson(route('projects.git.index', $this->project))
        ->assertJsonPath('github.settings_url', 'https://github.com/organizations/onedrop-io/settings/apps/onedrop-test/permissions')
        ->json('github.problems');

    expect(implode(' ', $problems))->toContain('Contents: Read and write')->toContain('Metadata')->toContain('Administration');
})->group('GIT-005');

test('admins are told which GitHub App settings are missing', function () {
    config(['services.github_app.client_id' => null, 'services.github_app.client_secret' => null]);
    $this->user->forceFill(['is_admin' => true])->save();

    $this->getJson(route('projects.git.index', $this->project))
        ->assertJsonPath('github.configured', false)
        ->assertJsonPath('github.problems.0', 'Add GITHUB_APP_CLIENT_ID, GITHUB_APP_CLIENT_SECRET to .env to finish setting up the GitHub App.');
})->group('GIT-005');

test('back from GitHub, the user\'s token is kept encrypted and only installations they can reach are remembered', function () {
    GitHubInstallation::factory()->for($this->user)->create(['installation_id' => 555]);
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_new', 'refresh_token' => 'ghr_new', 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600]),
        'api.github.com/user' => Http::response(['login' => 'dev']),
        'api.github.com/user/installations*' => Http::response(['installations' => [
            ['id' => 777, 'account' => ['login' => 'dev', 'type' => 'User', 'avatar_url' => null], 'repository_selection' => 'selected'],
            ['id' => 888, 'account' => ['login' => 'acme', 'type' => 'Organization', 'avatar_url' => 'https://avatars.example/acme'], 'repository_selection' => 'all'],
        ]]),
    ]);

    $this->withSession(['github_app' => ['state' => 'abc', 'project' => $this->project->id]])
        ->get(route('github-app.callback', ['state' => 'abc', 'code' => 'oauth-code', 'installation_id' => 888, 'setup_action' => 'install']))
        ->assertRedirect(route('projects.show', $this->project).'?tool=git&github=connect');

    $authorization = $this->user->githubAuthorization()->first();

    expect($this->user->githubInstallations()->orderBy('installation_id')->pluck('installation_id')->all())->toBe([777, 888])
        ->and($authorization->access_token)->toBe('ghu_new')
        ->and($authorization->refresh_token)->toBe('ghr_new')
        ->and($authorization->expires_at->isFuture())->toBeTrue()
        ->and(DB::table('github_authorizations')->value('access_token'))->not->toContain('ghu_new');
})->group('GIT-005');

test('an expired sign-in is refreshed, and one that can\'t be asks the user to reconnect', function () {
    $this->user->githubAuthorization()->update(['expires_at' => now()->subMinute()]);
    ($this->github)([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user', 'refresh_token' => 'ghr_next', 'expires_in' => 28800]),
    ]);

    $this->getJson(route('projects.git.github-app.repositories', [$this->project, 'installation_id' => 777]))->assertOk();
    expect($this->user->githubAuthorization()->first()->refresh_token)->toBe('ghr_next');

    $this->user->githubAuthorization()->update(['expires_at' => now()->subMinute(), 'refresh_expires_at' => now()->subDay()]);
    $this->user->unsetRelation('githubAuthorization');

    $this->getJson(route('projects.git.github-app.repositories', [$this->project, 'installation_id' => 777]))
        ->assertUnauthorized()
        ->assertJsonPath('reconnect', true);
    expect($this->user->githubAuthorization()->exists())->toBeFalse();
})->group('GIT-005');

test('a return from GitHub without the session that started it is refused', function () {
    $this->withSession(['github_app' => ['state' => 'abc', 'project' => $this->project->id]])
        ->get(route('github-app.callback', ['state' => 'forged', 'code' => 'x']))
        ->assertForbidden();

    $this->flushSession();
    $this->get(route('github-app.callback', ['state' => 'abc', 'code' => 'x']))->assertForbidden();
})->group('GIT-005');

test('installed without signing in through the app, the user signs in next; an approval request or cancel says so', function () {
    $session = ['github_app' => ['state' => 'abc', 'project' => $this->project->id]];

    $this->withSession($session)
        ->get(route('github-app.callback', ['state' => 'abc', 'installation_id' => 777, 'setup_action' => 'install']))
        ->assertRedirect('https://github.com/login/oauth/authorize?client_id=Iv1.client&state=abc');

    $this->withSession($session)
        ->get(route('github-app.callback', ['state' => 'abc', 'setup_action' => 'request']))
        ->assertRedirectContains('github_error=');

    $this->withSession($session)
        ->get(route('github-app.callback', ['state' => 'abc', 'error' => 'access_denied']))
        ->assertRedirectContains('github_error=GitHub+sign-in+was+cancelled.');
})->group('GIT-005');

test('repositories are listed with the user\'s own token, most recently pushed first', function () {
    ($this->github)();

    $this->getJson(route('projects.git.github-app.repositories', [$this->project, 'installation_id' => 777]))
        ->assertOk()
        ->assertJsonPath('repositories.0.full_name', 'dev/timer')
        ->assertJsonPath('repositories.0.private', true)
        ->assertJsonPath('repositories.0.empty', false)
        ->assertJsonPath('repositories.1.full_name', 'dev/old-site');

    $this->getJson(route('projects.git.github-app.branches', [$this->project, 'installation_id' => 777, 'repository' => 'dev/timer']))
        ->assertJsonPath('branches', ['main', 'redesign']);

    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/installation/repositories'));
})->group('GIT-005');

test('a new repository name is checked for being free', function () {
    ($this->github)();

    $this->getJson(route('projects.git.github-app.availability', [$this->project, 'installation_id' => 777, 'name' => 'timer']))->assertJsonPath('available', false);
    $this->getJson(route('projects.git.github-app.availability', [$this->project, 'installation_id' => 777, 'name' => 'brand-new']))->assertJsonPath('available', true);
    $this->getJson(route('projects.git.github-app.availability', [$this->project, 'installation_id' => 777, 'name' => 'no spaces']))->assertUnprocessable();
})->group('GIT-005');

test('someone else\'s installation is refused', function () {
    GitHubInstallation::factory()->create(['installation_id' => 999]);

    $this->getJson(route('projects.git.github-app.repositories', [$this->project, 'installation_id' => 999]))->assertForbidden();
    $this->putJson(route('projects.git.github-app.connect', $this->project), ['installation_id' => 999, 'repository' => 'them/app'])->assertForbidden();
    $this->postJson(route('projects.git.github-app.create', $this->project), ['installation_id' => 999, 'name' => 'app'])->assertForbidden();
})->group('GIT-005');

test('a repository the user can\'t reach is not connected', function () {
    ($this->github)();

    $this->putJson(route('projects.git.github-app.connect', $this->project), ['installation_id' => 888, 'repository' => 'acme/secret'])
        ->assertUnprocessable();

    expect($this->project->fresh()->git_remote_url)->toBeNull();
})->group('GIT-005');

test('a new organization repository is created by the app, connected and pushed to', function () {
    ($this->github)([
        'api.github.com/orgs/acme/repos' => fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer ghs_installation') && $request['name'] === 'team-timer' && $request['private'] === true
            ? Http::response(($this->repository)('acme/team-timer', ['size' => 0]), 201)
            : Http::response([], 400),
    ]);

    $this->postJson(route('projects.git.github-app.create', $this->project), ['installation_id' => 888, 'name' => 'team-timer', 'private' => true])
        ->assertOk()
        ->assertJsonPath('remote.url', 'https://github.com/acme/team-timer.git')
        ->assertJsonPath('remote.github_app', true)
        ->assertJsonPath('remote.sync_status', 'pushing');

    expect($this->project->fresh()->github_installation_id)->toBe(888);
    Queue::assertPushed(SyncGitRemote::class, fn (SyncGitRemote $job) => $job->direction === GitSyncStatus::Pushing);
})->group('GIT-005');

test('repositories are not created on personal accounts or without the Administration permission', function () {
    ($this->github)();

    $this->postJson(route('projects.git.github-app.create', $this->project), ['installation_id' => 777, 'name' => 'team-timer'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "OneDrop can't create repositories on dev. Create it on GitHub, then connect it.");

    $this->permissions = ['contents' => 'write', 'metadata' => 'read'];
    cache()->flush();

    $this->postJson(route('projects.git.github-app.create', $this->project), ['installation_id' => 888, 'name' => 'team-timer'])->assertUnprocessable();
    Queue::assertNothingPushed();
})->group('GIT-005');

test('connecting a repository keeps no token; an empty one is pushed to, a full one is left for the user', function () {
    ($this->github)();

    $this->putJson(route('projects.git.github-app.connect', $this->project), ['installation_id' => 777, 'repository' => 'dev/timer', 'branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('remote.url', 'https://github.com/dev/timer.git')
        ->assertJsonPath('remote.github_app', true)
        ->assertJsonPath('remote.sync_status', null);

    expect($this->project->fresh()->only(['github_installation_id', 'git_remote_token']))->toBe(['github_installation_id' => 777, 'git_remote_token' => null]);
    Queue::assertNothingPushed();

    $this->putJson(route('projects.git.github-app.connect', $this->project), ['installation_id' => 777, 'repository' => 'dev/empty'])
        ->assertJsonPath('remote.sync_status', 'pushing');
    Queue::assertPushed(SyncGitRemote::class, fn (SyncGitRemote $job) => $job->direction === GitSyncStatus::Pushing);
})->group('GIT-005');

test('connecting a repository to a project with no commits brings in the chosen branch', function (?string $branch, string $pulled) {
    ($this->github)();
    $this->head = null;

    $this->putJson(route('projects.git.github-app.connect', $this->project), ['installation_id' => 777, 'repository' => 'dev/timer', 'branch' => $branch])
        ->assertOk()
        ->assertJsonPath('remote.sync_status', 'pulling');

    Queue::assertPushed(SyncGitRemote::class, fn (SyncGitRemote $job) => $job->direction === GitSyncStatus::Pulling && $job->branch === $pulled);
})->with([
    'a chosen branch' => ['redesign', 'redesign'],
    'the default branch' => [null, 'trunk'],
])->group('GIT-005');

test('pushing a GitHub App repository uses a fresh installation token as the password', function () {
    ($this->github)();
    Storage::fake('backups');
    config(['sandbox.backup_disk' => 'backups']);
    Storage::disk('backups')->put("project-backups/{$this->project->id}/repo.bundle", 'bundle');
    $this->project->update(['git_remote_url' => 'https://github.com/dev/timer.git', 'github_installation_id' => 777]);
    $this->provider->execUsing = fn (array $command, array $env) => isset($env['APP_GIT_REQUEST'])
        ? new ExecResult(0, json_encode(['ok' => true, 'data' => ['head' => $this->head, 'branch' => 'main']]))
        : new ExecResult(3, '');
    Process::fake();

    app(GitRemote::class)->push($this->project);

    Process::assertRan(function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $headerKey = array_search('http.extraHeader', $process->environment, true);

        return str_contains($command, ' push ')
            && $headerKey !== false
            && $process->environment[str_replace('KEY', 'VALUE', $headerKey)] === 'Authorization: Basic '.base64_encode('x-access-token:ghs_installation');
    });
})->group('GIT-005');
