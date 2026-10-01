<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/onedrop-dockerd-'.uniqid();
    File::ensureDirectoryExists("{$this->dir}/bin");
    File::ensureDirectoryExists("{$this->dir}/run/docker/containerd");

    // Stand-ins: /var/lib/docker is its own volume, dockerd only reports that it started, and pgrep finds a
    // dockerd only when told to (the machine running the tests may have a real one).
    File::put("{$this->dir}/bin/mountpoint", "#!/bin/sh\nexit 0\n");
    File::put("{$this->dir}/bin/dockerd", "#!/bin/sh\necho \"dockerd \$*\"\n");
    File::put("{$this->dir}/bin/pgrep", "#!/bin/sh\n[ -n \"\$DOCKERD_RUNNING\" ]\n");

    foreach (['mountpoint', 'dockerd', 'pgrep'] as $command) {
        chmod("{$this->dir}/bin/{$command}", 0755);
    }

    File::put("{$this->dir}/run/docker.pid", (string) getmypid());
    File::put("{$this->dir}/run/docker/containerd/containerd.pid", (string) getmypid());

    $this->run = fn (bool $dockerdRunning = false) => Process::env([
        'PATH' => "{$this->dir}/bin:".getenv('PATH'),
        'ONEDROP_RUN_DIR' => "{$this->dir}/run",
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
        ->and($result->output())->toContain('dockerd --group docker')
        ->and("{$this->dir}/run/docker.pid")->not->toBeFile()
        ->and("{$this->dir}/run/docker/containerd/containerd.pid")->not->toBeFile();
})->group('SBX-008');

test('a running dockerd\'s pid files are left alone', function () {
    ($this->run)(dockerdRunning: true);

    expect("{$this->dir}/run/docker.pid")->toBeFile()
        ->and("{$this->dir}/run/docker/containerd/containerd.pid")->toBeFile();
})->group('SBX-008');
