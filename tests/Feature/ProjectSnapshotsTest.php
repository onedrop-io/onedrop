<?php

use App\Actions\DeleteProject;
use App\Jobs\BackupProject;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Hosting\HostingChanges;
use App\Sandbox\ProjectBackups;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxTools;
use App\Sandbox\SandboxUpdater;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * A fake sandbox that answers the snapshot tool: fingerprints from $fingerprints, packs whatever it's asked to, and
 * hands the packed layers over when they're copied out.
 */
function snapshotProvider(): FakeSandboxProvider
{
    $provider = new class extends FakeSandboxProvider
    {
        /** @var array<string, string> */
        public array $fingerprints = ['workspace' => 'w1', 'deps' => 'd1', 'home' => 'h1', 'storage' => 's1'];

        /** @var list<list<string>> layers packed by each pack command */
        public array $packed = [];

        /** @var list<array{string, string}> [layer, source] restored */
        public array $restored = [];

        public bool $failRestore = false;

        public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
        {
            $this->executed[] = ['id' => $id, 'command' => $command, 'env' => $env, 'detach' => $detach];
            $tool = SandboxTools::PATH.'/snapshot';

            return match (true) {
                // Every tool current, on the current base.
                ($command[3] ?? null) === 'hash' => new ExecResult(0, collect(app(SandboxTools::class)->expected())->map(fn ($hash, $path) => "{$hash}  {$path}")->implode("\n")),
                $command === [$tool, 'fingerprint'] => new ExecResult(0, collect($this->fingerprints)->map(fn ($hash, $layer) => "{$layer} {$hash}")->implode("\n")),
                ($command[0] ?? null) === $tool && $command[1] === 'pack' => $this->pack(array_slice($command, 2, -1)),
                ($command[0] ?? null) === $tool && $command[1] === 'restore' => $this->restore($command[2], $command[3]),
                default => new ExecResult(0, ''),
            };
        }

        public function copyOut(string $id, string $path, string $directory): void
        {
            parent::copyOut($id, $path, $directory);

            foreach (end($this->packed) ?: [] as $layer) {
                file_put_contents("{$directory}/{$layer}.tar.zst", "{$layer} archive");
            }
        }

        /**
         * @param  list<string>  $layers
         */
        protected function pack(array $layers): ExecResult
        {
            $this->packed[] = $layers;

            return new ExecResult(0, collect($layers)->map(fn ($layer) => "{$layer} zst 1048576")->implode("\n"));
        }

        protected function restore(string $layer, string $source): ExecResult
        {
            if ($this->failRestore) {
                return new ExecResult(1, '', 'zstd: corrupted block');
            }

            $this->restored[] = [$layer, $source];

            return new ExecResult(0, '');
        }
    };

    app()->instance(SandboxProvider::class, $provider);

    return $provider;
}

beforeEach(function () {
    Storage::fake('local');
    config(['sandbox.provider' => 'docker', 'sandbox.snapshot_disk' => 'local']);
    app()->instance(Publisher::class, new FakePublisher);
    $this->provider = snapshotProvider();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['agent_session_id' => 'ses_1']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['provider' => 'docker', 'external_id' => 'old-ctr']);
});

test('a snapshot keeps every layer of the project on the snapshot disk', function () {
    $snapshot = app(ProjectSnapshots::class)->take($this->project, 'turn');

    expect($snapshot->reason)->toBe('turn')
        ->and(array_keys($snapshot->layers))->toBe(ProjectSnapshots::LAYERS)
        ->and($snapshot->size)->toBe(4 * 1048576)
        ->and($this->provider->packed)->toBe([ProjectSnapshots::LAYERS]);

    foreach ($snapshot->layers as $layer => $meta) {
        expect($meta['path'])->toBe("project-snapshots/{$this->project->id}/layers/{$layer}-{$this->provider->fingerprints[$layer]}.tar.zst");
        Storage::disk('local')->assertExists($meta['path']);
    }
})->group('SBX-009');

test('only the layers that changed are packed and stored again', function () {
    $first = app(ProjectSnapshots::class)->take($this->project, 'turn');
    $this->provider->fingerprints['workspace'] = 'w2';

    $second = app(ProjectSnapshots::class)->take($this->project, 'turn');

    expect($this->provider->packed[1])->toBe(['workspace'])
        ->and($second->id)->not->toBe($first->id)
        ->and($second->layers['workspace']['path'])->toContain('workspace-w2')
        ->and($second->layers['deps'])->toBe($first->layers['deps'])
        ->and($second->layers['home'])->toBe($first->layers['home']);

    // Nothing changed: no new snapshot.
    expect(app(ProjectSnapshots::class)->take($this->project, 'turn')->id)->toBe($second->id)
        ->and($this->provider->packed)->toHaveCount(2);
})->group('SBX-009');

test('with an S3-compatible disk, the sandbox uploads its layers itself through signed links', function () {
    config(['filesystems.disks.local.driver' => 's3']);
    Storage::fake('local', ['serve' => true]);
    Storage::disk('local')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => ['Content-Type' => 'application/zstd']]);

    app(ProjectSnapshots::class)->take($this->project, 'turn');

    $puts = collect($this->provider->executed)->filter(fn ($run) => ($run['command'][1] ?? null) === 'put');

    expect($puts)->toHaveCount(4)
        ->and($this->provider->copied)->toBe([]) // nothing went through the platform
        ->and($puts->first()['env']['ONEDROP_SNAPSHOT_URL'])->toStartWith("https://bucket.test/project-snapshots/{$this->project->id}/layers/workspace-w1")
        ->and($puts->first()['env']['ONEDROP_SNAPSHOT_HEADERS'])->toBe('Content-Type: application/zstd')
        ->and(collect($puts->pluck('command'))->flatten()->implode(' '))->not->toContain('signed'); // never on a command line
})->group('SBX-009');

test('a remote sandbox with a local snapshot disk takes no snapshot', function () {
    $this->sandbox->update(['provider' => 'runtime']);

    expect(app(ProjectSnapshots::class)->take($this->project, 'turn'))->toBeNull()
        ->and($this->provider->packed)->toBe([]);
})->group('SBX-009');

test('an update moves the project to a new sandbox through a snapshot, and deletes the old one after', function () {
    $this->provider->outdated = ['old-ctr'];

    expect(app(SandboxUpdater::class)->recreate($this->project, keepFiles: true)->external_id)->not->toBe('old-ctr');

    $new = $this->sandbox->fresh()->external_id;

    expect($this->project->snapshots()->sole()->reason)->toBe('update')
        ->and($this->provider->paused)->toBe(['old-ctr'])
        ->and(collect($this->provider->restored)->pluck(0)->all())->toBe(ProjectSnapshots::LAYERS)
        ->and(collect($this->provider->copied)->where(0, 'in')->pluck(1)->unique()->all())->toBe([$new])
        ->and(collect($this->provider->copied)->where(0, 'out')->pluck(2)->all())->not->toContain('/workspace') // not the old copy
        ->and($this->provider->created)->not->toHaveKey('old-ctr')
        ->and($this->project->fresh()->agent_session_id)->toBe('ses_1')
        ->and(collect($this->provider->executed)->where('id', $new)->pluck('command')->all())->toContain(['/opt/onedrop/restart']);
})->group('SBX-009');

test('an update whose snapshot can\'t be restored goes back to the old sandbox', function () {
    $this->provider->created['old-ctr'] = new SandboxSpec('old');
    $this->provider->failRestore = true;

    expect(fn () => app(SandboxUpdater::class)->recreate($this->project, keepFiles: true))->toThrow(SandboxException::class, 'corrupted block');

    expect($this->sandbox->fresh()->external_id)->toBe('old-ctr')
        ->and($this->provider->created)->toHaveKey('old-ctr')
        ->and($this->provider->started)->toBe(['old-ctr']);
})->group('SBX-009');

test('a project whose sandbox is gone gets its latest snapshot back, not just its code', function () {
    app(ProjectSnapshots::class)->take($this->project, 'turn');
    $this->sandbox->update(['external_id' => null]);

    app(SandboxUpdater::class)->recreate($this->project, keepFiles: true);

    expect(collect($this->provider->restored)->pluck(0)->all())->toBe(ProjectSnapshots::LAYERS)
        ->and($this->project->fresh()->agent_session_id)->toBe('ses_1');
})->group('SBX-009');

test('sandbox:restore gives the project a new sandbox from an earlier snapshot', function () {
    $first = app(ProjectSnapshots::class)->take($this->project, 'turn');
    $this->provider->fingerprints['workspace'] = 'w2';
    app(ProjectSnapshots::class)->take($this->project, 'turn');

    $this->artisan('sandbox:restore', ['project' => $this->project->id, 'snapshot' => $first->id])->assertSuccessful();

    $restored = collect($this->provider->restored)->firstWhere(0, 'workspace')[1];

    expect($restored)->toEndWith('/workspace.tar.zst')
        ->and($this->project->snapshots()->count())->toBe(2) // no snapshot of the sandbox being replaced
        ->and($this->sandbox->fresh()->external_id)->not->toBe('old-ctr');
})->group('SBX-009');

test('old snapshots are pruned, keeping the latest ten and one a day for a week, and layers still used', function () {
    $shared = 'project-snapshots/'.$this->project->id.'/layers/deps-shared.tar.zst';
    Storage::disk('local')->put($shared, 'deps');
    $make = function (CarbonInterface $at, string $name) use ($shared) {
        Storage::disk('local')->put("project-snapshots/{$this->project->id}/layers/{$name}.tar.zst", $name);
        $snapshot = $this->project->snapshots()->create(['reason' => 'turn', 'size' => 2, 'layers' => [
            'workspace' => ['path' => "project-snapshots/{$this->project->id}/layers/{$name}.tar.zst", 'fingerprint' => $name, 'compression' => 'zst', 'size' => 1],
            'deps' => ['path' => $shared, 'fingerprint' => 'shared', 'compression' => 'zst', 'size' => 1],
        ]]);
        $snapshot->forceFill(['created_at' => $at])->save();

        return $snapshot;
    };

    $old = $make(now()->subDays(20), 'old');
    $threeDaysAgo = $make(now()->subDays(3), 'three-days-ago');
    foreach (range(1, 10) as $i) {
        $make(now()->subMinutes(100 - $i), "recent-{$i}");
    }

    expect(app(ProjectSnapshots::class)->prune($this->project))->toBe(1);

    expect(ProjectSnapshot::find($old->id))->toBeNull()
        ->and(ProjectSnapshot::find($threeDaysAgo->id))->not->toBeNull();
    Storage::disk('local')->assertMissing("project-snapshots/{$this->project->id}/layers/old.tar.zst");
    Storage::disk('local')->assertExists($shared);
})->group('SBX-009');

test('a snapshot is taken after every turn, with the code backup', function () {
    (new BackupProject($this->project))->handle(app(ProjectBackups::class), app(ProjectSnapshots::class), app(HostingChanges::class));

    expect($this->project->snapshots()->sole()->reason)->toBe('turn');
})->group('SBX-009');

test('the daily snapshot only takes projects whose sandbox was used that day', function () {
    $this->sandbox->forceFill(['last_active_at' => now()->subHours(3)])->save();
    $dormant = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($dormant)->create(['provider' => 'docker', 'external_id' => 'dormant-ctr', 'last_active_at' => now()->subDays(3)]);

    $this->artisan('sandbox:snapshot')->assertSuccessful();

    expect($this->project->snapshots()->sole()->reason)->toBe('daily')
        ->and($dormant->snapshots()->count())->toBe(0)
        ->and(collect($this->provider->executed)->where('id', 'dormant-ctr'))->toBeEmpty();
})->group('SBX-009');

test('deleting a project deletes its snapshots', function () {
    app(ProjectSnapshots::class)->take($this->project, 'turn');

    app(DeleteProject::class)->handle($this->project);

    expect(Storage::disk('local')->allFiles("project-snapshots/{$this->project->id}"))->toBe([]);
})->group('SBX-009');
