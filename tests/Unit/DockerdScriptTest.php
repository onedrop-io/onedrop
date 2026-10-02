<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/onedrop-dockerd-'.uniqid();
    File::ensureDirectoryExists("{$this->dir}/bin");
    File::ensureDirectoryExists("{$this->dir}/run/docker/containerd");

    // Stand-ins: stat reports the filesystem Docker's folder is on, dockerd only reports that it started, and pgrep
    // finds a dockerd only when told to (the machine running the tests may have a real one).
    File::put("{$this->dir}/bin/stat", "#!/bin/sh\necho \"\${DATA_FS:-ext2/ext3}\"\n");
    File::put("{$this->dir}/bin/dockerd", "#!/bin/sh\necho \"dockerd \$*\"\n");
    File::put("{$this->dir}/bin/pgrep", "#!/bin/sh\n[ -n \"\$DOCKERD_RUNNING\" ]\n");

    foreach (['stat', 'dockerd', 'pgrep'] as $command) {
        chmod("{$this->dir}/bin/{$command}", 0755);
    }

    File::put("{$this->dir}/run/docker.pid", (string) getmypid());
    File::put("{$this->dir}/run/docker/containerd/containerd.pid", (string) getmypid());

    File::put("{$this->dir}/max_map_count", "65530\n");

    $this->run = fn (bool $dockerdRunning = false, string $filesystem = 'ext2/ext3') => Process::env([
        'PATH' => "{$this->dir}/bin:".getenv('PATH'),
        'ONEDROP_RUN_DIR' => "{$this->dir}/run",
        'ONEDROP_DOCKER_DIR' => "{$this->dir}/docker-data",
        'ONEDROP_MAX_MAP_COUNT_FILE' => "{$this->dir}/max_map_count",
        'DATA_FS' => $filesystem,
        'DOCKERD_RUNNING' => $dockerdRunning ? '1' : '',
    ])->run(['bash', dirname(__DIR__, 2).'/docker/sandbox/dockerd']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('a restarted sandbox\'s stale pid files are removed, so Docker starts even when their pid is in use', function () {
    // The last run's pids are now taken by another process (this one).
    $result = ($this->run)();

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain("dockerd --group sandbox --data-root {$this->dir}/docker-data")
        ->and("{$this->dir}/run/docker.pid")->not->toBeFile()
        ->and("{$this->dir}/run/docker/containerd/containerd.pid")->not->toBeFile();
})->group('SBX-008');

test('a running dockerd\'s pid files are left alone', function () {
    ($this->run)(dockerdRunning: true);

    expect("{$this->dir}/run/docker.pid")->toBeFile()
        ->and("{$this->dir}/run/docker/containerd/containerd.pid")->toBeFile();
})->group('SBX-008');

test('Docker starts on a real disk, such as a VM sandbox\'s, and raises Elasticsearch\'s memory map limit', function () {
    $result = ($this->run)();

    expect($result->successful())->toBeTrue()
        ->and(trim(File::get("{$this->dir}/max_map_count")))->toBe('262144');
})->group('SBX-008');

test('Docker refuses to start on the sandbox\'s overlay filesystem, saying why', function () {
    $result = ($this->run)(filesystem: 'overlayfs');

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('on the sandbox\'s overlay filesystem')
        ->and($result->output())->not->toContain('dockerd --group');
})->group('SBX-008');
