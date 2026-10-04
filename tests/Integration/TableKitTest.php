<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/*
 * Installs the table kit into a fresh Laravel React starter kit, the way the agent does, and runs the kit's own tests
 * and a type check there. Needs network access (Composer and npm). Opt in with:
 * RUN_KIT_TESTS=1 vendor/bin/pest tests/Integration/TableKitTest.php
 */

beforeEach(function () {
    if (! env('RUN_KIT_TESTS')) {
        $this->markTestSkipped('Set RUN_KIT_TESTS=1 to run the table kit in a fresh starter kit.');
    }
});

function kitStep(array $command, string $cwd, array $env = []): Process
{
    $process = new Process($command, $cwd, $env, null, 900);
    $process->run();

    expect($process->getExitCode())->toBe(0, implode(' ', $command)."\n".$process->getOutput().$process->getErrorOutput());

    return $process;
}

test('the table kit installs into a fresh starter kit and its tests and types pass there', function () {
    $sandbox = dirname(__DIR__, 2).'/docker/sandbox';
    $app = sys_get_temp_dir().'/onedrop-table-kit-'.bin2hex(random_bytes(4));

    try {
        kitStep(['composer', 'create-project', 'laravel/react-starter-kit:dev-main', $app, '--no-interaction', '--prefer-dist'], sys_get_temp_dir());
        kitStep(['npm', 'install', '--no-audit', '--no-fund'], $app);
        kitStep([$sandbox.'/kit', 'tables'], $app, ['KIT_DIR' => $sandbox.'/kits', 'KIT_APP_DIR' => $app]);

        expect(kitStep(['php', 'artisan', 'test', 'tests/Feature/Tables', 'tests/Unit/Tables'], $app)->getOutput())->not->toContain('FAIL');

        kitStep(['npx', 'tsc', '--noEmit'], $app);
    } finally {
        (new Filesystem)->deleteDirectory($app);
    }
})->group('TABLE-001');
