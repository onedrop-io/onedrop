<?php

use App\Sandbox\SandboxTools;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

test('the tool files are every file the image copies into /opt/onedrop, except the base files', function () {
    $files = (new SandboxTools(base_path('docker/sandbox')))->files();

    expect($files)->toHaveKeys(['host-proxy.mjs', 'forwarder.mjs', 'guides/auth.md', 'placeholder/index.php', 'sshd_config', 'restart'])
        ->and(array_intersect(array_keys($files), SandboxTools::BASE_FILES))->toBe([])
        ->and(array_keys(SandboxTools::RESTARTS))->each->toBeIn(array_keys($files));
})->group('SBX-002');

test('every file in docker/sandbox is either a tool file or a base file, so none is left out of updates', function () {
    $source = base_path('docker/sandbox');
    $tools = array_map('realpath', (new SandboxTools($source))->files());
    $untracked = collect(File::allFiles($source))
        ->map(fn ($file) => $file->getRelativePathname())
        // Blaxel's additions are built into its own image, which never gets tool files copied in (SBX-004).
        ->reject(fn (string $path) => str_starts_with($path, 'blaxel/') || in_array($path, SandboxTools::BASE_FILES, true))
        ->reject(fn (string $path) => in_array(realpath("{$source}/{$path}"), $tools, true))
        ->values()
        ->all();

    expect($untracked)->toBe([]);
})->group('SBX-002');

test('the image keeps a copy of its base files where the platform reads them', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/sandbox/Dockerfile');

    expect($dockerfile)->toContain('COPY '.implode(' ', SandboxTools::BASE_FILES).' '.SandboxTools::BASE_PATH.'/');
})->group('SBX-002');
