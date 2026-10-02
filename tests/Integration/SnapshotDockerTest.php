<?php

use App\Jobs\CreateSandbox;
use App\Models\Project;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Talks to real Docker. Opt in with: RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration
 * Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }
});

test('a project moves to a new sandbox through a snapshot with its files, dependencies, home folder and a consistent database', function () {
    $this->artisan('migrate:fresh');
    $disk = sys_get_temp_dir().'/onedrop-snapshots-'.bin2hex(random_bytes(3));
    config([
        'sandbox.provider' => 'docker',
        'filesystems.disks.snapshots' => ['driver' => 'local', 'root' => $disk],
        'sandbox.snapshot_disk' => 'snapshots',
    ]);
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    app()->instance(SandboxProvider::class, $docker);
    app()->instance(Publisher::class, new FakePublisher);

    $project = Project::factory()->create();
    CreateSandbox::dispatchSync($project);
    $old = $project->sandbox()->firstOrFail()->external_id;
    $new = null;
    $sh = fn (string $id, string $script) => $docker->exec($id, ['bash', '-c', "cd /workspace && {$script}"]);

    try {
        $setup = 'mkdir -p .onedrop/data node_modules/left-pad src/vendor && echo "module.exports=1" > node_modules/left-pad/index.js'
            .' && echo "{}" > package-lock.json && echo "lib" > src/vendor/lib.js && echo "uncommitted" > notes.txt && echo "mine" > ~/notes'
            .' && sqlite3 .onedrop/data/app.db "create table hits (id integer primary key, at text)"'
            .' && (setsid bash -c "while true; do sqlite3 .onedrop/data/app.db \"insert into hits (at) values (datetime())\"; done" >/dev/null 2>&1 &)';
        expect($sh($old, $setup)->successful())->toBeTrue();
        sleep(1);

        $started = microtime(true);
        $sandbox = app(SandboxUpdater::class)->recreate($project, keepFiles: true);
        $took = microtime(true) - $started;
        $new = $sandbox->external_id;
        $snapshot = $project->snapshots()->sole();

        expect($new)->not->toBe($old)
            ->and(trim($sh($new, 'cat notes.txt node_modules/left-pad/index.js src/vendor/lib.js ~/notes')->output))->toBe("uncommitted\nmodule.exports=1\nlib\nmine")
            ->and(trim($sh($new, 'sqlite3 .onedrop/data/app.db "pragma integrity_check"')->output))->toBe('ok')
            ->and((int) trim($sh($new, 'sqlite3 .onedrop/data/app.db "select count(*) from hits"')->output))->toBeGreaterThan(0)
            ->and(array_keys($snapshot->layers))->toBe(ProjectSnapshots::LAYERS)
            ->and(Storage::disk('snapshots')->exists($snapshot->layers['deps']['path']))->toBeTrue()
            ->and($docker->exec($old, ['true'])->successful())->toBeFalse(); // deleted

        fwrite(STDERR, sprintf("\n  moved through a snapshot in %.1fs (%s KB stored)\n", $took, number_format($snapshot->size / 1024, 1)));

        // A turn later, only what changed is stored again.
        $sh($new, 'echo "more" >> notes.txt');
        $next = app(ProjectSnapshots::class)->take($project->fresh(), 'turn');

        expect($next->layers['workspace']['path'])->not->toBe($snapshot->layers['workspace']['path'])
            ->and($next->layers['deps'])->toBe($snapshot->layers['deps']);
    } finally {
        foreach (array_filter([$old, $new]) as $id) {
            $docker->destroy($id);
        }

        File::deleteDirectory($disk);
    }
})->group('SBX-009');
