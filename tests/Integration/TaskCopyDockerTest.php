<?php

use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\TaskCopies;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

test("a task's copy has Main's files and a consistent database mid-write, and its work merges back", function () {
    $this->artisan('migrate:fresh');
    $storage = sys_get_temp_dir().'/onedrop-task-copy-'.bin2hex(random_bytes(3));
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.storage_path' => $storage, 'sandbox.task_copies' => true]);
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    app()->instance(SandboxProvider::class, $docker);

    $project = Project::factory()->create();
    CreateSandbox::dispatchSync($project);
    $main = $project->sandbox()->firstOrFail()->external_id;
    $task = Task::factory()->for($project)->create(['title' => 'Dark mode']);
    $task->sandbox()->create(['provider' => 'docker', 'status' => SandboxStatus::Creating]);
    $copy = null;
    $sh = fn (string $id, string $script) => $docker->exec($id, ['bash', '-c', "cd /workspace && {$script}"]);

    try {
        // An app with a dev server, a dependency folder, and a SQLite database written to constantly (no stack knowledge needed).
        $setup = 'mkdir -p .onedrop/data node_modules/left-pad public && echo "module.exports=1" > node_modules/left-pad/index.js'
            .' && echo "<h1>Main</h1>" > public/index.html'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public\n" > .onedrop/dev && chmod +x .onedrop/dev'
            .' && sqlite3 .onedrop/data/app.db "create table hits (id integer primary key, at text)"'
            .' && echo "Build the app" | /opt/onedrop/checkpoint && /opt/onedrop/restart'
            .' && (setsid bash -c "while true; do sqlite3 .onedrop/data/app.db \"insert into hits (at) values (datetime())\"; done" >/dev/null 2>&1 &)';
        expect($sh($main, $setup)->successful())->toBeTrue();
        sleep(2);

        expect(app(TaskCopies::class)->fork($task))->toBe([]);

        $copy = $task->sandbox()->firstOrFail()->external_id;

        expect(trim($sh($copy, 'git branch --show-current')->output))->toBe("task-{$task->id}")
            ->and(trim($sh($copy, 'cat node_modules/left-pad/index.js')->output))->toBe('module.exports=1')
            ->and(trim($sh($copy, 'sqlite3 .onedrop/data/app.db "pragma integrity_check"')->output))->toBe('ok')
            ->and((int) trim($sh($copy, 'sqlite3 .onedrop/data/app.db "select count(*) from hits"')->output))->toBeGreaterThan(0)
            ->and($task->fresh()->base_commit)->toBe(trim($sh($main, 'git rev-parse HEAD')->output))
            // Main kept running while it was copied.
            ->and(trim($sh($main, 'ps -o stat= -p "$(pgrep -f "insert into hits" | head -1)"')->output))->not->toContain('T');

        $preview = $task->sandbox()->firstOrFail()->preview_url;
        expect(retry(20, fn () => Http::timeout(2)->get($preview)->throw()->body(), 500))->toContain('<h1>Main</h1>');

        // The task changes the app; Main doesn't see it until it's applied.
        expect($sh($copy, 'echo "<h1>Main</h1><p>Dark mode</p>" > public/index.html && echo "body{background:#000}" > public/dark.css')->successful())->toBeTrue()
            ->and(trim($sh($main, 'test -e public/dark.css && echo yes || echo no')->output))->toBe('no');

        expect(app(TaskCopies::class)->apply($task->fresh()))->toBe([]);

        expect(trim($sh($main, 'cat public/dark.css')->output))->toBe('body{background:#000}')
            ->and(trim($sh($main, 'git log -1 --format=%s')->output))->toBe('Apply task: Dark mode')
            ->and(trim($sh($main, 'git status --porcelain')->output))->toBe('');
    } finally {
        $docker->destroy($main);

        if ($copy) {
            $docker->destroy($copy);
        }

        File::deleteDirectory($storage);
    }
})->group('TASK-003');
