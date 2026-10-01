<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/onedrop-dockerd-'.uniqid();
    File::ensureDirectoryExists("{$this->dir}/bin");
    File::ensureDirectoryExists("{$this->dir}/run/docker/containerd");

    // Stand-ins: /var/lib/docker is its own volume, and dockerd only reports that it started.
    File::put("{$this->dir}/bin/mountpoint", "#!/bin/sh\nexit 0\n");
    File::put("{$this->dir}/bin/dockerd", "#!/bin/sh\necho \"dockerd \$*\"\n");
    chmod("{$this->dir}/bin/mountpoint", 0755);
    chmod("{$this->dir}/bin/dockerd", 0755);

    $this->run = fn () => Process::env([
        'PATH' => "{$this->dir}/bin:".getenv('PATH'),
        'ONEDROP_RUN_DIR' => "{$this->dir}/run",
    ])->run(['bash', dirname(__DIR__, 2).'/docker/sandbox/dockerd']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('a restarted sandbox\'s stale pid files are removed, so Docker starts even when their pid is in use', function () {
    // The last run's pids, now taken by another process (this one).
    File::put("{$this->dir}/run/docker.pid", (string) getmypid());
    File::put("{$this->dir}/run/docker/containerd/containerd.pid", (string) getmypid());

    $result = ($this->run)();

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('dockerd --group docker')
        ->and("{$this->dir}/run/docker.pid")->not->toBeFile()
        ->and("{$this->dir}/run/docker/containerd/containerd.pid")->not->toBeFile();
})->group('SBX-008');
