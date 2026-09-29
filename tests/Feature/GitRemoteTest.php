<?php

use App\Enums\GitSyncStatus;
use App\Jobs\SyncGitRemote;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
    config(['sandbox.backup_disk' => 'backups', 'sandbox.git.protocols' => ['https', 'file']]);

    $this->root = sys_get_temp_dir().'/zap-git-remote-'.uniqid();
    $this->workspace = "{$this->root}/workspace";
    $this->remote = "{$this->root}/remote.git";
    $this->sh = fn (string $command, ?string $path = null) => trim(Process::path($path ?? $this->workspace)->run($command)->throw()->output());
    $commit = '-c user.name=Me -c user.email=me@example.com commit -q';
    $this->commit = $commit;

    // The sandbox's repository, and the remote it pushes to.
    File::ensureDirectoryExists($this->workspace);
    ($this->sh)("git init -q -b main && echo one > a.txt && git add a.txt && git {$commit} -m One");
    ($this->sh)("git init -q --bare {$this->remote}", $this->root);

    $this->gitRequests = [];
    $this->copiedIn = null;
    $test = $this;

    $this->provider = new class($test) extends FakeSandboxProvider
    {
        public function __construct(public $test) {}

        public function copyOut(string $id, string $path, string $directory): void
        {
            // The backup bundle of the sandbox's repository (a stand-in while git itself is faked).
            if ($this->test->fakeGit ?? false) {
                File::put("{$directory}/repo.bundle", 'bundle');

                return;
            }

            Process::path($this->test->workspace)->run(['git', 'bundle', 'create', '-q', "{$directory}/repo.bundle", '--all'])->throw();
        }

        public function copyIn(string $id, string $directory, string $path): void
        {
            File::copyDirectory($directory, $this->test->copiedIn = "{$this->test->root}/copied-in");
        }
    };
    $this->provider->execUsing = function (array $command, array $env) {
        if (isset($env['APP_GIT_REQUEST'])) {
            $request = json_decode($env['APP_GIT_REQUEST'], true);
            $this->gitRequests[] = $request;

            return new ExecResult(0, json_encode(['ok' => true, 'data' => ['head' => ($this->sh)('git rev-parse HEAD'), 'branch' => 'main']]));
        }

        // Bundling for the backup.
        return new ExecResult(0, ($this->sh)('git rev-parse HEAD'));
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->project = Project::factory()->for(User::factory()->has(AgentConnection::factory()))->create([
        'git_remote_url' => "file://{$this->remote}",
        'git_remote_token' => 'token',
    ]);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('pushing sends the sandbox\'s branch to the remote and records it', function () {
    app(GitRemote::class)->push($this->project);

    $head = ($this->sh)('git rev-parse HEAD');

    expect(($this->sh)('git rev-parse refs/heads/main', $this->remote))->toBe($head)
        ->and(collect($this->gitRequests)->last())->toBe(['op' => 'pushed', 'branch' => 'main', 'sha' => $head]);
})->group('GIT-004');

test('a push the remote rejects says to pull first, and the panel shows it', function () {
    app(GitRemote::class)->push($this->project);
    // Someone else pushes; the sandbox commits something different.
    $other = "{$this->root}/other";
    Process::run(['git', 'clone', '-q', $this->remote, $other])->throw();
    ($this->sh)("echo theirs > b.txt && git add b.txt && git {$this->commit} -m Theirs && git push -q origin main", $other);
    ($this->sh)("echo mine > a.txt && git {$this->commit} -am Mine");

    SyncGitRemote::dispatchSync($this->project, GitSyncStatus::Pushing);

    expect($this->project->fresh()->git_sync_status)->toBe(GitSyncStatus::Failed)
        ->and($this->project->fresh()->git_sync_error)->toBe("The remote has commits this project doesn't. Pull first.");
})->group('GIT-004');

test('pulling copies the remote\'s branch into the sandbox as a bundle to fast-forward to', function () {
    app(GitRemote::class)->push($this->project);
    $other = "{$this->root}/other";
    Process::run(['git', 'clone', '-q', $this->remote, $other])->throw();
    ($this->sh)("echo theirs > b.txt && git add b.txt && git {$this->commit} -m Theirs && git push -q origin main", $other);

    SyncGitRemote::dispatchSync($this->project, GitSyncStatus::Pulling);

    expect(($this->sh)('git bundle list-heads pull.bundle', $this->copiedIn))->toBe(($this->sh)('git rev-parse HEAD', $other).' refs/heads/main')
        ->and(collect($this->gitRequests)->last())->toBe(['op' => 'pulled', 'branch' => 'main', 'bundle' => '/tmp/zap-pull/pull.bundle'])
        ->and($this->project->fresh()->git_sync_status)->toBeNull()
        ->and($this->project->fresh()->git_synced_at)->not->toBeNull();
})->group('GIT-004');

test('pulling a branch the remote does not have yet says to push it', function () {
    expect(fn () => app(GitRemote::class)->pull($this->project))
        ->toThrow(GitException::class, "The remote doesn't have this branch yet. Push it first.");
})->group('GIT-004');

test('the token goes to the remote as a header, never in the URL, with this machine\'s git settings ignored', function () {
    $this->fakeGit = true;
    $this->project->update(['git_remote_url' => 'https://git.example.com/dev/timer.git', 'git_remote_username' => 'dev']);
    config(['sandbox.git.allow_private_remotes' => true]);
    Process::fake();

    app(GitRemote::class)->push($this->project);

    Process::assertRan(function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        if (! str_contains($command, ' push ')) {
            return false;
        }

        $env = $process->environment;
        $headerKey = array_search('http.extraHeader', $env, true);

        return str_contains($command, 'https://git.example.com/dev/timer.git')
            && ! str_contains($command, 'token')
            && $env['GIT_CONFIG_GLOBAL'] === '/dev/null'
            && $env['GIT_TERMINAL_PROMPT'] === '0'
            && $env[str_replace('KEY', 'VALUE', $headerKey)] === 'Authorization: Basic '.base64_encode('dev:token');
    });
})->group('GIT-004');
