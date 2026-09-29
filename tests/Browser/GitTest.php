<?php

use App\Jobs\SyncGitRemote;
use App\Models\AgentConnection;
use App\Models\GitHubAuthorization;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the git section commits changes, restores a version and connects a remote as the dev user', function () {
    Queue::fake();
    config(['sandbox.git.allow_private_remotes' => true]);

    $commit = fn (string $sha, string $subject, string $author) => [
        'sha' => str_repeat($sha, 40), 'subject' => $subject, 'author' => $author, 'email' => 'x@example.com',
        'date' => now()->subHour()->toIso8601String(), 'agent' => $author === 'OneDrop',
    ];
    $state = [
        'changes' => [['path' => 'resources/js/app.tsx', 'status' => 'M']],
        'commits' => [$commit('b', 'Make the timer blue', 'OneDrop'), $commit('a', 'Build a timer', 'OneDrop')],
    ];
    $requests = [];

    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) use (&$state, &$requests, $commit) {
        if (! isset($env['APP_GIT_REQUEST'])) {
            return new ExecResult(0, '');
        }

        $request = json_decode($env['APP_GIT_REQUEST'], true);
        $requests[] = $request;

        if ($request['op'] === 'commit') {
            $state['changes'] = [];
            array_unshift($state['commits'], $commit('c', $request['message'], $request['name']));
        }

        if ($request['op'] === 'restore') {
            array_unshift($state['commits'], $commit('d', 'Restore "Build a timer" (aaaaaaa)', $request['name']));
        }

        if ($request['op'] === 'show') {
            return new ExecResult(0, json_encode(['ok' => true, 'data' => [
                ...collect($state['commits'])->firstWhere('sha', $request['sha']),
                'body' => 'Asked for: make the timer blue.', 'parents' => [str_repeat('a', 40)], 'more_files' => false,
                'files' => [['path' => 'resources/css/app.css', 'status' => 'M', 'additions' => 3, 'deletions' => 1, 'binary' => false]],
            ]]));
        }

        if ($request['op'] === 'diff') {
            return new ExecResult(0, json_encode(['ok' => true, 'data' => [
                'path' => $request['path'], 'truncated' => false,
                'patch' => "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n .timer {\n-  color: black;\n+  color: blue;\n",
            ]]));
        }

        $data = $request['op'] === 'log' ? [
            'commits' => array_values(array_filter($state['commits'], fn (array $commit) => ! ($request['query'] ?? null) || str_contains(strtolower($commit['subject']), strtolower($request['query'])))),
            'more' => false,
        ] : [
            'initialized' => true, 'branch' => 'main', 'branches' => ['main'], 'head' => $state['commits'][0]['sha'],
            'changes' => $state['changes'], 'more_changes' => false, 'tracking' => null, 'state' => null,
        ];

        return new ExecResult(0, json_encode(['ok' => true, 'data' => $data]));
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Dev User', 'email' => 'dev@example.com']);
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-git')
        ->assertVisible('@git-panel')
        ->assertSeeIn('@git-branch', 'main')
        ->assertSeeIn('@git-history', 'Make the timer blue')
        ->assertSeeIn('@git-history', 'Agent · 1 hour ago · bbbbbbb')
        ->assertSeeIn('@git-change-count', '1 changed file')
        ->assertSeeIn('@git-changes', 'resources/js/app.tsx')
        ->type('@git-history-search', 'build')
        ->assertDontSeeIn('@git-history', 'Make the timer blue')
        ->assertSeeIn('@git-history', 'Build a timer')
        ->type('@git-history-search', 'nothing like it')
        ->assertSeeIn('@git-history', 'No commits match "nothing like it".')
        ->clear('@git-history-search')
        ->assertSeeIn('@git-history', 'Make the timer blue')
        ->click('[data-test="git-commit-row"]:first-child [data-test="git-commit-toggle"]')
        ->assertSeeIn('@git-commit-details', 'Asked for: make the timer blue.')
        ->assertSeeIn('@git-commit-sha', str_repeat('b', 40))
        ->assertSeeIn('@git-commit-files', '+3')
        ->click('@git-commit-file')
        ->assertSeeIn('@git-diff', '+  color: blue;')
        ->assertDontSeeIn('@git-diff', 'diff --git')
        ->click('[data-test="git-commit-row"]:first-child [data-test="git-commit-toggle"]')
        ->assertMissing('@git-commit-details')
        ->type('@git-message', 'Tweak the start button')
        ->click('@git-commit-button')
        ->assertSeeIn('@git-change-count', 'No changes')
        ->assertSeeIn('@git-history', 'Tweak the start button')
        ->assertSeeIn('@git-history', 'Dev User')
        ->hover('[data-test="git-commit-row"]:last-child')
        ->click('[data-test="git-commit-row"]:last-child [data-test="git-commit-menu"]')
        ->click('@git-restore')
        ->assertSeeIn('@git-confirm', 'Restore this version?')
        ->click('@git-confirm-action')
        ->assertSeeIn('@git-history', 'Restore "Build a timer"')
        ->assertScript('new Set([...document.querySelectorAll(\'[data-test="git-commit-toggle"] > svg:last-child\')].map((arrow) => Math.round(arrow.getBoundingClientRect().right))).size', 1)
        ->click('@git-connect-existing')
        ->type('@git-remote-url', 'https://git.example.com/dev/timer.git')
        ->type('@git-remote-token', 'secret-token')
        ->click('@git-remote-save')
        ->assertSeeIn('@git-remote', 'git.example.com/dev/timer')
        ->assertSeeIn('@git-remote-state', 'Not pushed yet')
        ->click('@git-push')
        ->assertSeeIn('@git-remote-state', 'Pushing…')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->firstWhere('op', 'restore')['sha'])->toBe(str_repeat('a', 40))
        ->and($project->fresh()->git_remote_token)->toBe('secret-token');
    Queue::assertPushed(SyncGitRemote::class);
})->group('GIT-001', 'GIT-002', 'GIT-003', 'GIT-004');

/**
 * A GitHub App, a user signed in through it with their account and an organization, and a sandbox whose
 * git tool reports $head as the latest commit (null: no commits yet).
 */
function githubSetup(object $test, ?string $head, bool &$created = false): Project
{
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    config(['services.github_app' => ['id' => '1', 'slug' => 'onedrop-test', 'client_id' => 'Iv1.x', 'client_secret' => 's', 'private_key' => $pem]]);

    $repository = fn (string $fullName, array $extra = []) => [
        'full_name' => $fullName, 'name' => explode('/', $fullName)[1], 'private' => true, 'default_branch' => 'main', 'size' => 10,
        'clone_url' => "https://github.com/{$fullName}.git", 'html_url' => "https://github.com/{$fullName}", 'pushed_at' => now()->subDays(2)->toIso8601String(), ...$extra,
    ];

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_x'], 201),
        'api.github.com/app' => Http::response(['owner' => ['login' => 'onedrop-io', 'type' => 'Organization'], 'permissions' => ['contents' => 'write', 'metadata' => 'read', 'administration' => 'write']]),
        'api.github.com/user/installations/777/repositories*' => function () use (&$created, $repository) {
            return Http::response(['repositories' => [
                $repository('dev/timer'),
                $repository('dev/blog', ['private' => false, 'pushed_at' => now()->subYear()->toIso8601String()]),
                ...($created ? [$repository('dev/team-timer', ['size' => 0])] : []),
            ]]);
        },
        'api.github.com/user/installations/888/repositories*' => Http::response(['repositories' => []]),
        'api.github.com/repos/dev/timer/branches*' => Http::response([['name' => 'main'], ['name' => 'redesign']]),
        'api.github.com/repos/dev/timer' => Http::response($repository('dev/timer')),
        'api.github.com/repos/dev/team-timer' => function () use (&$created, $repository) {
            return $created ? Http::response($repository('dev/team-timer', ['size' => 0])) : Http::response([], 404);
        },
        'api.github.com/orgs/acme/repos' => Http::response($repository('acme/team-timer', ['size' => 0]), 201),
        'api.github.com/repos/*' => Http::response([], 404),
    ]);

    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command, array $env) => new ExecResult(0, json_encode(['ok' => true, 'data' => [
        'commits' => [], 'more' => false, 'initialized' => $head !== null, 'branch' => $head ? 'main' : null, 'branches' => $head ? ['main'] : [], 'head' => $head,
        'changes' => [], 'more_changes' => false, 'tracking' => null, 'state' => null,
    ]]));
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create(['name' => 'Team Timer']);
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    GitHubInstallation::factory()->for($user)->create(['installation_id' => 777, 'account_login' => 'dev', 'account_type' => 'User', 'repository_selection' => 'selected']);
    GitHubInstallation::factory()->for($user)->create(['installation_id' => 888, 'account_login' => 'acme', 'account_type' => 'Organization']);
    GitHubAuthorization::factory()->for($user)->create(['github_login' => 'dev']);
    $test->actingAs($user);

    return $project;
}

test('back from GitHub, the dialog opens on existing repositories and an empty project brings one in', function () {
    Queue::fake();
    $project = githubSetup($this, head: null);

    visit("/projects/{$project->id}?tool=git&github=connect")
        ->resize(1500, 1000)
        ->assertVisible('@git-github-dialog')
        ->assertSeeIn('@git-github-dialog', 'Signed in to GitHub as @dev')
        ->assertSeeIn('@git-github-repositories', 'timer')
        ->assertSeeIn('@git-github-repositories', 'Updated 2 days ago')
        ->assertSeeIn('@git-github-repositories', 'Public')
        ->type('@git-github-filter', 'tim')
        ->assertDontSeeIn('@git-github-repositories', 'blog')
        ->click('@git-github-repository')
        ->select('@git-github-branch', 'redesign')
        ->assertSeeIn('@git-github-connect', 'Connect and bring it in')
        ->click('@git-github-connect')
        ->assertMissing('@git-github-dialog')
        ->assertSeeIn('@git-remote', 'github.com/dev/timer')
        ->assertSeeIn('@git-remote-state', 'Pulling…')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->github_installation_id)->toBe(777);
    Queue::assertPushed(SyncGitRemote::class, fn (SyncGitRemote $job) => $job->branch === 'redesign');
})->group('GIT-005');

test('a new organization repository is created and pushed to from the dialog', function () {
    Queue::fake();
    $project = githubSetup($this, head: str_repeat('a', 40));

    visit("/projects/{$project->id}?tool=git")
        ->resize(1500, 1000)
        ->click('@git-connect-github')
        ->assertSeeIn('@git-github-dialog', 'New repository')
        ->click('[data-test="git-github-owner"]:nth-child(2)')
        ->assertValue('@git-github-name', 'team-timer')
        ->assertSeeIn('@git-github-availability', 'team-timer is available.')
        ->click('@git-github-create')
        ->assertMissing('@git-github-dialog')
        ->assertSeeIn('@git-remote', 'github.com/acme/team-timer')
        ->assertSeeIn('@git-remote-state', 'Pushing…')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->github_installation_id)->toBe(888);
    Queue::assertPushed(SyncGitRemote::class);
})->group('GIT-005');

test('on a personal account, GitHub opens filled in and the new repository is found and connected on return', function () {
    Queue::fake();
    $created = false;
    $project = githubSetup($this, str_repeat('a', 40), $created);

    $page = visit("/projects/{$project->id}?tool=git")
        ->resize(1500, 1000)
        ->click('@git-connect-github')
        ->assertSeeIn('@git-github-availability', 'team-timer is available.')
        ->assertAttribute('@git-github-create-on-github', 'href', 'https://github.com/new?owner=dev&name=team-timer&visibility=private');

    // The user creates it on GitHub.
    $created = true;

    $page->click('@git-github-create-on-github')
        ->assertSeeIn('@git-github-waiting', 'Found dev/team-timer')
        ->click('@git-github-connect-found')
        ->assertMissing('@git-github-dialog')
        ->assertSeeIn('@git-remote-state', 'Pushing…')
        ->assertNoJavaScriptErrors();

    Queue::assertPushed(SyncGitRemote::class);
})->group('GIT-005');

test('the first time, the dialog explains installing the app on GitHub', function () {
    $project = githubSetup($this, head: str_repeat('a', 40));
    $project->user->githubAuthorization()->delete();

    visit("/projects/{$project->id}?tool=git")
        ->resize(1500, 1000)
        ->click('@git-connect-github')
        ->assertSeeIn('@git-github-install', 'Install the OneDrop app')
        ->assertAttribute('@git-github-continue', 'href', route('projects.git.github-app.install', $project))
        ->assertNoJavaScriptErrors();
})->group('GIT-005');

test('coming back from GitHub without its redirect offers to finish instead of starting over', function () {
    $project = githubSetup($this, head: str_repeat('a', 40));
    $project->user->githubAuthorization()->delete();
    $project->user->githubInstallations()->delete();
    $project->user->forceFill(['is_admin' => true])->save();
    $this->actingAs($project->user);

    $page = visit("/projects/{$project->id}?tool=git")
        ->resize(1500, 1000)
        ->click('@git-connect-github')
        ->assertSeeIn('@git-github-admin-note', route('github-app.callback'));

    // They clicked "Continue to GitHub", installed, and came back by hand.
    $page->script("sessionStorage.setItem('github-connect-started:{$project->id}', '1')");
    $page->navigate("/projects/{$project->id}?tool=git")
        ->assertSeeIn('@git-github-came-back', 'Finished installing on GitHub?')
        ->assertAttribute('@git-github-finish', 'href', route('projects.git.github-app.install', [$project, 'reconnect' => 1]))
        ->assertNoJavaScriptErrors();
})->group('GIT-005');

test('discarding a file named "all" discards only that file', function () {
    $requests = [];
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) use (&$requests) {
        if (! isset($env['APP_GIT_REQUEST'])) {
            return new ExecResult(0, '');
        }

        $request = json_decode($env['APP_GIT_REQUEST'], true);
        $requests[] = $request;

        $data = match ($request['op']) {
            'log' => ['commits' => [], 'more' => false],
            default => [
                'initialized' => true, 'branch' => 'main', 'branches' => ['main'], 'head' => str_repeat('a', 40),
                'changes' => [['path' => 'all', 'status' => 'M'], ['path' => 'index.html', 'status' => 'M']],
                'more_changes' => false, 'tracking' => null, 'state' => null,
            ],
        };

        return new ExecResult(0, json_encode(['ok' => true, 'data' => $data]));
    };
    app()->instance(SandboxProvider::class, $provider);

    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    visit("/projects/{$project->id}")
        ->resize(1500, 1000)
        ->click('@tab-tools')
        ->click('@tool-git')
        ->assertSeeIn('@git-change-count', '2 changed files')
        ->click('[aria-label="Discard changes to all"]')
        ->assertSee('Discard changes to this file?')
        ->click('@git-confirm-action')
        ->assertNoJavaScriptErrors();

    expect(collect($requests)->where('op', 'discard')->values()->all())->toBe([['op' => 'discard', 'path' => 'all']]);
})->group('GIT-002');
