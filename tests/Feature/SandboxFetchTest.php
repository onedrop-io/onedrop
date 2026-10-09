<?php

use App\Jobs\ImportRepository;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
 * A GitHub App repository's pull is fetched by the sandbox itself, in the background (GIT-004, PRJ-009). The fake
 * sandbox runs the real scripts here, in a temporary folder, against a local repository standing in for GitHub.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config(['services.github_app' => ['id' => '12345', 'slug' => 'onedrop-test', 'client_id' => 'Iv1.client', 'client_secret' => 'secret', 'private_key' => $pem]]);

    $this->tokenRequests = [];
    Http::fake(['api.github.com/app/installations/777/access_tokens' => function (HttpRequest $request) {
        $this->tokenRequests[] = $request->data();

        return Http::response(['token' => 'ghs_read'], 201);
    }]);

    $this->root = sys_get_temp_dir().'/onedrop-sandbox-fetch-'.uniqid();
    $this->fetchDirectory = "{$this->root}/fetch";
    $this->remote = "{$this->root}/remote.git";
    register_shutdown_function(fn () => File::deleteDirectory($this->root));

    // GitHub's copy of the repository.
    File::ensureDirectoryExists("{$this->root}/source");
    Process::path("{$this->root}/source")->run('git init -q -b main && echo hi > a.txt && git add a.txt && git -c user.name=Me -c user.email=me@example.com commit -q -m One && git clone -q --bare . '.escapeshellarg($this->remote))->throw();

    // macOS has no `timeout`; the sandbox does.
    File::ensureDirectoryExists("{$this->root}/bin");
    File::put("{$this->root}/bin/timeout", "#!/bin/sh\nshift\nexec \"\$@\"\n");
    chmod("{$this->root}/bin/timeout", 0755);

    $this->runInBackground = true;
    $this->gitRequests = [];
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = function (array $command, array $env) {
        if (isset($env['APP_GIT_REQUEST'])) {
            $this->gitRequests[] = json_decode($env['APP_GIT_REQUEST'], true);

            return new ExecResult(0, json_encode(['ok' => true, 'data' => ['head' => null, 'branch' => 'main']]));
        }

        $command = array_map(fn (string $part) => $part === '/tmp/onedrop-fetch' ? $this->fetchDirectory : $part, $command);

        if ($command[0] === 'rm') {
            File::deleteDirectory($this->fetchDirectory);

            return new ExecResult(0, '');
        }

        if ($command[0] !== 'bash' || ($command[3] ?? null) !== 'fetch') {
            return new ExecResult(0, '');
        }

        // The background fetch: run now, or leave it "still running".
        if (isset($env['ONEDROP_FETCH_AUTH']) && ! $this->runInBackground) {
            return new ExecResult(0, '');
        }

        $result = Process::env([...$env, 'ONEDROP_FETCH_URL' => "file://{$this->remote}", 'PATH' => "{$this->root}/bin:".getenv('PATH')])->run($command);

        return new ExecResult($result->exitCode(), $result->output(), $result->errorOutput());
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->project = Project::factory()->for(User::factory()->has(AgentConnection::factory()))->create([
        'git_remote_url' => 'https://github.com/acme/app.git',
        'github_installation_id' => 777,
    ]);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('a GitHub App repository is fetched by the sandbox with a token that can only read it', function () {
    $remote = app(GitRemote::class);
    $this->runInBackground = false;

    expect($remote->fetchesInSandbox($this->project))->toBeTrue()
        ->and($remote->pullStep($this->project, 'main'))->toBeFalse()
        ->and($this->tokenRequests)->toBe([['repositories' => ['app'], 'permissions' => ['contents' => 'read']]]);

    $fetch = collect($this->provider->executed)->firstWhere('detach', true);
    expect($fetch['env'])->toMatchArray([
        'ONEDROP_FETCH_URL' => 'https://github.com/acme/app.git',
        'ONEDROP_FETCH_BRANCH' => 'main',
        'ONEDROP_FETCH_AUTH' => 'Authorization: Basic '.base64_encode('x-access-token:ghs_read'),
    ])->and(collect($this->provider->executed)->pluck('command')->flatten()->implode(' '))->not->toContain('ghs_read');

    // Still downloading: check again later.
    expect($remote->pullStep($this->project, 'main'))->toBeFalse();

    // It finishes: the commits come in as a bundle, and the sandbox's folder is cleared.
    $this->runInBackground = true;
    $this->provider->exec('ctr-1', $fetch['command'], $fetch['env'], detach: true);
    expect(Process::run(['git', 'bundle', 'list-heads', "{$this->fetchDirectory}/pull.bundle"])->output())->toContain('refs/heads/main');

    expect($remote->pullStep($this->project, 'main'))->toBeTrue()
        ->and(collect($this->gitRequests)->last())->toBe(['op' => 'pulled', 'branch' => 'main', 'bundle' => '/tmp/onedrop-fetch/pull.bundle'])
        ->and(File::exists($this->fetchDirectory))->toBeFalse();
})->group('GIT-004', 'PRJ-009');

test('a fetch the remote refuses says why, and the next pull starts afresh', function () {
    $remote = app(GitRemote::class);

    expect($remote->pullStep($this->project, 'missing'))->toBeFalse();
    expect(fn () => $remote->pullStep($this->project, 'missing'))->toThrow(GitException::class, 'The remote doesn\'t have this branch yet.');
    expect(File::exists($this->fetchDirectory))->toBeFalse();
})->group('GIT-004');

test('a fetch that ran out of time, or stopped without finishing, says so', function (string $state, string $exit, string $message) {
    File::ensureDirectoryExists($this->fetchDirectory);
    File::put("{$this->fetchDirectory}/branch", 'main');
    File::put("{$this->fetchDirectory}/state", "{$state}\n");
    File::put("{$this->fetchDirectory}/exit", $exit);
    // A process that has ended.
    File::put("{$this->fetchDirectory}/pid", '999999');

    expect(fn () => app(GitRemote::class)->pullStep($this->project))->toThrow(GitException::class, $message);
})->with([
    'out of time' => ['failed', "124\n", 'over 20 minutes'],
    'stopped' => ['running', '', 'The download stopped before it finished.'],
])->group('GIT-004');

test('an import checks back every few seconds while the sandbox downloads, in short attempts', function () {
    $this->runInBackground = false;
    $message = Message::factory()->for($this->project)->create();
    $job = (new ImportRepository($this->project, $message, 'main'))->withFakeQueueInteractions();

    $job->handle(app(GitRemote::class));

    $job->assertReleased(delay: ImportRepository::CHECK_SECONDS);
    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(20)->getTimestamp());
})->group('PRJ-009');

test('remotes whose token can\'t be narrowed are still fetched on the platform', function () {
    $this->project->update(['github_installation_id' => null, 'git_remote_token' => 'glpat-x']);

    expect(app(GitRemote::class)->fetchesInSandbox($this->project))->toBeFalse();

    $this->project->update(['github_installation_id' => 777, 'git_remote_url' => 'https://gitlab.com/acme/app.git']);

    expect(app(GitRemote::class)->fetchesInSandbox($this->project))->toBeFalse();
})->group('GIT-004');
