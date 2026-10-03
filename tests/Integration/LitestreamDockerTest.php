<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
 * Talks to real Docker, an S3 stand-in (adobe/s3mock, as the R2 bucket) and GitHub (Litestream's release). Opt in with:
 * RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration
 * Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }
});

test("a hosted app's SQLite database is backed up continuously and comes back on a new volume", function () {
    $image = config('sandbox.providers.docker.image');
    $id = bin2hex(random_bytes(3));
    $network = "onedrop-ls-{$id}";
    $s3 = "onedrop-ls-s3-{$id}";
    $app = "onedrop-ls-app-{$id}";
    $first = "onedrop-ls-a-{$id}";
    $second = "onedrop-ls-b-{$id}";
    $docker = fn (array $command, int $timeout = 120) => Process::timeout($timeout)->run(['docker', ...$command])->throw();
    $sqlite = fn (string $sql) => trim($docker(['exec', '--user', 'sandbox', $app, 'sqlite3', '/workspace/database/database.sqlite', $sql])->output());

    // The hosted machine, as Deployer configures it: the runner from the release, a volume at /data, Litestream's settings.
    $boot = fn (string $volume) => $docker(['run', '-d', '--name', $app, '--network', $network, '--user', 'root',
        '-v', base_path('docker/sandbox/hosting').':/opt/onedrop-hosting/run:ro', '-v', "{$volume}:/data",
        '-e', 'ONEDROP_VOLUME=1', '-e', 'ONEDROP_DATA=database/database.sqlite', '-e', 'ONEDROP_SQLITE=database/database.sqlite',
        '-e', 'ONEDROP_BACKUP_BUCKET=backups', '-e', "ONEDROP_BACKUP_ENDPOINT=http://{$s3}:9090",
        '-e', 'LITESTREAM_ACCESS_KEY_ID=onedrop', '-e', 'LITESTREAM_SECRET_ACCESS_KEY=onedrop-secret',
        '-e', 'ONEDROP_LITESTREAM_VERSION='.config('hosting.litestream_version'),
        '--entrypoint', 'bash', $image, '-c',
        'mkdir -p /workspace/.onedrop && printf \'#!/usr/bin/env bash\nexec python3 -m http.server "$PORT"\n\' > /workspace/.onedrop/start'
        .' && chmod +x /workspace/.onedrop/start && exec /opt/onedrop-hosting/run run']);
    $waitFor = function (string $pattern) use ($app): string {
        foreach (range(1, 60) as $_) {
            $logs = Process::run(['docker', 'logs', $app])->output().Process::run(['docker', 'logs', $app])->errorOutput();

            if (preg_match($pattern, $logs)) {
                return $logs;
            }

            sleep(1);
        }

        throw new RuntimeException("Never saw {$pattern} in:\n".$logs);
    };

    try {
        $docker(['network', 'create', $network]);
        $docker(['run', '-d', '--name', $s3, '--network', $network, '-e', 'COM_ADOBE_TESTING_S3MOCK_STORE_INITIAL_BUCKETS=backups', '-e', 'initialBuckets=backups', 'adobe/s3mock'], 300);

        // A volume with the app's database on it, as the first deploy leaves it.
        $docker(['run', '--rm', '--user', 'root', '-v', "{$first}:/data", '--entrypoint', 'bash', $image, '-c',
            'mkdir -p /data/workspace/database /data/storage && sqlite3 /data/workspace/database/database.sqlite "create table doses (id integer primary key, mg int); insert into doses (mg) values (5), (10)"'
            .' && chown -R sandbox:sandbox /data && touch /data/.onedrop-seeded']);

        $boot($first);
        $waitFor('/litestream: .*(initialized db|replicating to)/');
        $sqlite('insert into doses (mg) values (20)');
        // Litestream syncs every second.
        sleep(5);
        $docker(['rm', '-f', $app]);

        // The volume is lost: a new, empty one gets the database back from its backup.
        $boot($second);
        $logs = $waitFor('/Restored database\/database\.sqlite from its backup\./');

        expect($sqlite('select group_concat(mg) from doses'))->toBe('5,10,20')
            ->and(trim($docker(['exec', $app, 'stat', '-c', '%U', '/data/workspace/database/database.sqlite'])->output()))->toBe('sandbox')
            ->and($logs)->not->toContain("Couldn't fetch Litestream");
    } finally {
        Process::run(['docker', 'rm', '-f', $app, $s3]);
        Process::run(['docker', 'volume', 'rm', '-f', $first, $second]);
        Process::run(['docker', 'network', 'rm', $network]);
    }
})->group('HOST-008');
