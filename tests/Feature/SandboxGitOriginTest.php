<?php

use App\Enums\ProjectKind;
use App\Jobs\SyncGitOrigin;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Sandbox\ExecResult;
use App\Sandbox\GitOrigin;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * The sandbox's repository has the project's remote as `origin`, and git gets a token that can only read a GitHub App
 * repository from the platform when it asks (GIT-021).
 */
beforeEach(function () {
    Http::preventStrayRequests();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config([
        'services.github_app' => ['id' => '12345', 'slug' => 'onedrop-test', 'client_id' => 'Iv1.client', 'client_secret' => 'secret', 'private_key' => $pem],
        'sandbox.callback_url' => 'https://onedrop.test',
    ]);

    $this->permissions = ['contents' => 'write', 'metadata' => 'read', 'pull_requests' => 'read'];
    $this->tokenRequests = [];
    Http::fake([
        'api.github.com/app' => fn () => Http::response(['permissions' => $this->permissions]),
        'api.github.com/app/installations/777/access_tokens' => function (HttpRequest $request) {
            $this->tokenRequests[] = $request->data();

            return Http::response(['token' => 'ghs_read'.count($this->tokenRequests)], 201);
        },
    ]);

    $this->gitRequests = [];
    $this->initialized = true;
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = function (array $command, array $env) {
        if (isset($env['APP_GIT_REQUEST'])) {
            $this->gitRequests[] = json_decode($env['APP_GIT_REQUEST'], true);

            return new ExecResult(0, json_encode(['ok' => true, 'data' => ['initialized' => $this->initialized]]));
        }

        return new ExecResult(0, '');
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->project = Project::factory()->create([
        'git_remote_url' => 'https://github.com/acme/app.git',
        'github_installation_id' => 777,
    ]);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('a sandbox\'s origin is the GitHub App repository, with a signed address its credential helper gets tokens from', function () {
    app(GitOrigin::class)->sync($this->sandbox);

    $request = collect($this->gitRequests)->sole();
    expect($request)->toMatchArray(['op' => 'origin', 'url' => 'https://github.com/acme/app.git', 'repository' => 'acme/app'])
        ->and($request['token_url'])->toStartWith("https://onedrop.test/sandbox-git/{$this->sandbox->id}/token?signature=")
        ->and(collect($this->provider->installed)->pluck('files')->flatten()->all())->toContain('git.php', 'lazy/git-credential-onedrop');

    // Up to date: not asked again.
    app(GitOrigin::class)->sync($this->sandbox);
    expect($this->gitRequests)->toHaveCount(1);
})->group('GIT-021');

test('a token remote gets origin without the credential helper, and no remote takes origin away', function () {
    $this->project->update(['github_installation_id' => null, 'git_remote_url' => 'https://gitlab.com/acme/app.git', 'git_remote_token' => 'glpat-x']);
    app(GitOrigin::class)->sync($this->sandbox->fresh());

    expect(collect($this->gitRequests)->last())->toBe(['op' => 'origin', 'url' => 'https://gitlab.com/acme/app.git', 'repository' => null, 'token_url' => null])
        ->and(json_encode($this->provider->executed))->not->toContain('glpat-x');

    $this->project->update(['git_remote_url' => null]);
    app(GitOrigin::class)->sync($this->sandbox->fresh());

    expect(collect($this->gitRequests)->last())->toBe(['op' => 'origin', 'url' => null, 'repository' => null, 'token_url' => null]);
})->group('GIT-021');

test('a project without a remote leaves sandboxes that never had origin alone, and a new one is checked once', function () {
    $this->project->update(['git_remote_url' => null, 'github_installation_id' => null]);

    app(GitOrigin::class)->sync($this->sandbox->fresh());
    expect($this->gitRequests)->toBe([]);

    app(GitOrigin::class)->sync($this->sandbox->fresh(), evenWithoutRemote: true);
    app(GitOrigin::class)->sync($this->sandbox->fresh(), evenWithoutRemote: true);
    expect($this->gitRequests)->toBe([['op' => 'origin', 'url' => null, 'repository' => null, 'token_url' => null]]);
})->group('GIT-021');

test('a sandbox whose repository hasn\'t come in yet is asked again', function () {
    $this->initialized = false;
    app(GitOrigin::class)->sync($this->sandbox);
    $this->initialized = true;
    app(GitOrigin::class)->sync($this->sandbox);
    app(GitOrigin::class)->sync($this->sandbox);

    expect($this->gitRequests)->toHaveCount(2);
})->group('GIT-021');

test('changing the remote updates the project\'s running sandboxes, task copies too', function () {
    Queue::fake();
    $task = Task::factory()->for($this->project)->create();
    $copy = Sandbox::factory()->create(['task_id' => $task->id, 'project_id' => $this->project->id, 'external_id' => 'ctr-task']);
    Sandbox::factory()->create(['external_id' => 'ctr-other']);

    $this->project->update(['name' => 'Renamed']);
    Queue::assertNotPushed(SyncGitOrigin::class);

    $this->project->update(['git_remote_url' => 'https://github.com/acme/other.git']);
    Queue::assertPushed(SyncGitOrigin::class, fn (SyncGitOrigin $job) => $job->for->is($this->project));

    (new SyncGitOrigin($this->project))->handle(app(GitOrigin::class));

    expect(collect($this->provider->executed)->filter(fn ($run) => isset($run['env']['APP_GIT_REQUEST']))->pluck('id')->sort()->values()->all())->toBe(['ctr-1', 'ctr-task'])
        ->and(collect($this->gitRequests)->pluck('repository')->unique()->all())->toBe(['acme/other']);
})->group('GIT-021');

test('a computer gets no origin', function () {
    $this->project->update(['kind' => ProjectKind::Computer]);

    app(GitOrigin::class)->sync($this->sandbox->fresh());

    expect($this->gitRequests)->toBe([]);
})->group('GIT-021');

test('the credential helper\'s address gives a token that can only read the repository and its pull requests, an hour at a time', function () {
    $url = app(GitOrigin::class)->settings($this->sandbox)['token_url'];
    $path = str_replace('https://onedrop.test', '', $url);

    $this->getJson($path)->assertOk()->assertExactJson(['username' => 'x-access-token', 'password' => 'ghs_read1']);
    $this->getJson($path)->assertOk()->assertJson(['password' => 'ghs_read1']);

    expect($this->tokenRequests)->toBe([['repositories' => ['app'], 'permissions' => ['contents' => 'read', 'pull_requests' => 'read']]]);

    // Without a signature, or for another sandbox's address, nothing.
    $this->getJson("/sandbox-git/{$this->sandbox->id}/token")->assertForbidden();
    $other = Sandbox::factory()->create();
    $this->getJson(str_replace("/sandbox-git/{$this->sandbox->id}/", "/sandbox-git/{$other->id}/", $path))->assertForbidden();
})->group('GIT-021');

test('the token leaves out pull requests when the GitHub App can\'t read them, and there\'s none for other remotes', function () {
    $this->permissions = ['contents' => 'write', 'metadata' => 'read'];
    $path = str_replace('https://onedrop.test', '', app(GitOrigin::class)->settings($this->sandbox)['token_url']);

    $this->getJson($path)->assertOk();
    expect($this->tokenRequests)->toBe([['repositories' => ['app'], 'permissions' => ['contents' => 'read']]]);

    $this->project->update(['github_installation_id' => null, 'git_remote_token' => 'ghp_x']);
    $this->getJson($path)->assertNotFound()->assertJsonMissing(['password' => 'ghp_x']);
})->group('GIT-021');

test('git\'s credential helper answers only for the project\'s repository on github.com', function () {
    $root = sys_get_temp_dir().'/onedrop-git-credential-'.uniqid();
    File::ensureDirectoryExists($root);
    File::put("{$root}/token.json", json_encode(['username' => 'x-access-token', 'password' => 'ghs_read']));
    File::put("{$root}/credentials", "repository=Acme/App\nurl=file://{$root}/token.json\n");
    $helper = base_path('docker/sandbox/lazy/git-credential-onedrop');

    $ask = fn (string $request, string $command = 'get') => Process::env(['ONEDROP_GIT_CREDENTIALS' => "{$root}/credentials"])
        ->input($request)->run([$helper, $command])->output();

    expect($ask("protocol=https\nhost=github.com\npath=acme/app.git\n\n"))->toBe("username=x-access-token\npassword=ghs_read\n")
        ->and($ask("protocol=https\nhost=github.com\npath=acme/app\n\n"))->toBe("username=x-access-token\npassword=ghs_read\n")
        ->and($ask("protocol=https\nhost=github.com\npath=acme/other.git\n\n"))->toBe('')
        ->and($ask("protocol=https\nhost=evil.example\npath=acme/app.git\n\n"))->toBe('')
        ->and($ask("protocol=http\nhost=github.com\npath=acme/app.git\n\n"))->toBe('')
        ->and($ask('', 'token'))->toBe("ghs_read\n");

    // Disconnected: nothing.
    File::delete("{$root}/credentials");
    expect($ask("protocol=https\nhost=github.com\npath=acme/app.git\n\n"))->toBe('');

    File::deleteDirectory($root);
})->group('GIT-021');
