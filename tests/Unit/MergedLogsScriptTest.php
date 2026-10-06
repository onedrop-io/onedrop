<?php

use App\Sandbox\SandboxServices;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/onedrop-merged-logs-'.uniqid();
    File::ensureDirectoryExists($this->bin);

    // `docker logs --timestamps --tail N NAME` prints that container's lines from a file, its last N.
    File::put("{$this->bin}/docker", <<<BASH
        #!/usr/bin/env bash
        tail -n "\$4" "{$this->bin}/\$5.log"
        BASH);
    chmod("{$this->bin}/docker", 0755);

    $this->merge = fn (string $lines, string ...$containers) => Process::env(['PATH' => "{$this->bin}:".getenv('PATH')])
        ->run(['bash', '-c', SandboxServices::MERGED_LOGS_SCRIPT, 'logs', $lines, ...$containers]);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

test('merges containers\' logs in time order, labelled, keeping each one\'s own order and the latest lines', function () {
    File::put("{$this->bin}/workspace-app-1.log", implode("\n", [
        '2026-10-05T10:00:01.000000000Z GET / 200 12ms',
        '2026-10-05T10:00:03.000000000Z first at the same time',
        '2026-10-05T10:00:03.000000000Z second at the same time',
        '2026-10-05T10:00:05.000000000Z key=value  two  spaces',
    ])."\n");
    File::put("{$this->bin}/workspace-db-1.log", implode("\n", [
        '2026-10-05T10:00:00.000000000Z database system is ready',
        '2026-10-05T10:00:04.000000000Z checkpoint complete',
    ])."\n");

    $result = ($this->merge)('200', 'workspace-app-1', 'app', 'workspace-db-1', 'db');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toBe(implode("\n", [
            "db\tdatabase system is ready",
            "app\tGET / 200 12ms",
            "app\tfirst at the same time",
            "app\tsecond at the same time",
            "db\tcheckpoint complete",
            "app\tkey=value  two  spaces",
        ])."\n");

    expect(($this->merge)('2', 'workspace-app-1', 'app', 'workspace-db-1', 'db')->output())
        ->toBe("db\tcheckpoint complete\napp\tkey=value  two  spaces\n");
})->group('SVC-002');
