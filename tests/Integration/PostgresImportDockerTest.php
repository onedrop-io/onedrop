<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
 * Talks to real Docker and Postgres. Opt in with: RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration
 * Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }
});

test("Move to Postgres copies a Laravel app's SQLite data into the tables its migrations made, and ids carry on", function () {
    $image = config('sandbox.providers.docker.image');
    $id = bin2hex(random_bytes(3));
    $network = "onedrop-pg-{$id}";
    $postgres = "onedrop-pg-db-{$id}";
    $sandbox = "onedrop-pg-app-{$id}";
    $docker = fn (array $command, int $timeout = 120) => Process::timeout($timeout)->run(['docker', ...$command]);
    $url = "postgresql://app:secret@{$postgres}:5432/app";
    $psql = fn (string $sql) => trim($docker(['exec', $postgres, 'psql', '-U', 'app', '-d', 'app', '-qtA', '-c', $sql])->throw()->output());

    try {
        $docker(['network', 'create', $network])->throw();
        $docker(['run', '-d', '--name', $postgres, '--network', $network, '-e', 'POSTGRES_USER=app', '-e', 'POSTGRES_PASSWORD=secret', 'postgres:16-alpine'])->throw();
        $docker(['run', '-d', '--name', $sandbox, '--network', $network, '-v', base_path('docker/sandbox/pg-import.php').':/opt/onedrop-hosting/pg-import.php:ro', $image, 'sleep', '600'])->throw();

        // SQLite as Laravel's SQLite migrations leave it: booleans as 0/1, timestamps as text, a blob, JSON text.
        $docker(['exec', $sandbox, 'bash', '-c', <<<'BASH'
            mkdir -p /tmp/app && sqlite3 /tmp/app/database.sqlite "
            create table migrations (id integer primary key autoincrement, migration varchar not null, batch integer not null);
            insert into migrations (migration, batch) values ('0001_create_users_table', 1), ('0002_create_doses_table', 1);
            create table users (id integer primary key autoincrement, name varchar not null, is_admin tinyint(1) not null default 0, avatar blob, settings text, created_at datetime);
            insert into users (name, is_admin, avatar, settings, created_at) values ('Ann', 1, x'00ff10', '{\"theme\":\"dark\"}', '2026-10-01 09:30:00'), ('Bob', 0, null, null, '2026-10-02 10:00:00');
            create table doses (id integer primary key autoincrement, user_id integer not null references users(id), mg integer not null, taken_at integer);
            insert into doses (user_id, mg, taken_at) values (2, 5, 1759500000), (1, 10, 1759500000000);
            delete from users where id = 99;"
            BASH])->throw();

        // Postgres as the same app's migrations make it there (doses before users on purpose: the copy orders them).
        // pg_isready passes during the image's first-run setup; the app database only answers once that's done.
        until(fn () => $docker(['exec', $postgres, 'psql', '-U', 'app', '-d', 'app', '-h', '127.0.0.1', '-c', 'select 1'])->successful());
        $psql(<<<'SQL'
            create table migrations (id serial primary key, migration varchar(255) not null, batch integer not null);
            insert into migrations (migration, batch) values ('0001_create_users_table', 1), ('0002_create_doses_table', 1);
            create table users (id bigserial primary key, name varchar(255) not null, is_admin boolean not null default false, avatar bytea, settings json, created_at timestamp(0));
            create table doses (id bigserial primary key, user_id bigint not null references users(id), mg integer not null, taken_at timestamp(0));
            SQL);

        $result = $docker(['exec', '-e', "DATABASE_URL={$url}", $sandbox, 'php', '/opt/onedrop-hosting/pg-import.php', '/tmp/app/database.sqlite']);

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and(json_decode($result->output(), true))->toBe(['tables' => 2, 'rows' => 4])
            ->and($psql("select string_agg(name || ':' || is_admin || ':' || coalesce(encode(avatar, 'hex'), '-') || ':' || coalesce(settings->>'theme', '-') || ':' || created_at, ',' order by id) from users"))
            ->toBe('Ann:true:00ff10:dark:2026-10-01 09:30:00,Bob:false:-:-:2026-10-02 10:00:00')
            ->and($psql("select string_agg(user_id || ':' || mg || ':' || taken_at, ',' order by id) from doses"))
            ->toBe('2:5:2025-10-03 14:00:00,1:10:2025-10-03 14:00:00')
            // Laravel's migrations table stays as the migrations made it.
            ->and($psql('select count(*) from migrations'))->toBe('2');

        // New rows get the next ids.
        expect($psql("insert into users (name) values ('Cy') returning id"))->toBe('3');

        // A table the migrations didn't make stops it, with nothing copied.
        $docker(['exec', $sandbox, 'sqlite3', '/tmp/app/database.sqlite', 'create table notes (body text); insert into notes values (\'hi\')'])->throw();
        $psql('truncate users, doses restart identity cascade');
        $failed = $docker(['exec', '-e', "DATABASE_URL={$url}", $sandbox, 'php', '/opt/onedrop-hosting/pg-import.php', '/tmp/app/database.sqlite']);

        expect($failed->exitCode())->toBe(1)
            ->and($failed->errorOutput())->toContain("table notes isn't in Postgres")
            ->and($psql('select count(*) from users'))->toBe('0');
    } finally {
        $docker(['rm', '-f', $postgres, $sandbox]);
        $docker(['network', 'rm', $network]);
    }
})->group('HOST-009');

/**
 * Wait (up to 30 seconds) until the check passes.
 */
function until(Closure $check): void
{
    foreach (range(1, 60) as $_) {
        if ($check()) {
            return;
        }

        usleep(500_000);
    }

    throw new RuntimeException('Timed out waiting.');
}

test('the hosted machine moves its SQLite data into Postgres once, before the app starts, then starts it', function () {
    $image = config('sandbox.providers.docker.image');
    $id = bin2hex(random_bytes(3));
    $network = "onedrop-mv-{$id}";
    $postgres = "onedrop-mv-db-{$id}";
    $app = "onedrop-mv-app-{$id}";
    $volume = "onedrop-mv-vol-{$id}";
    $docker = fn (array $command, int $timeout = 120) => Process::timeout($timeout)->run(['docker', ...$command]);
    $psql = fn (string $sql) => trim($docker(['exec', $postgres, 'psql', '-U', 'app', '-d', 'app', '-qtA', '-c', $sql])->throw()->output());
    $url = "postgresql://app:secret@{$postgres}:5432/app";

    try {
        $docker(['network', 'create', $network])->throw();
        $docker(['run', '-d', '--name', $postgres, '--network', $network, '-e', 'POSTGRES_USER=app', '-e', 'POSTGRES_PASSWORD=secret', 'postgres:16-alpine'])->throw();
        until(fn () => $docker(['exec', $postgres, 'psql', '-U', 'app', '-d', 'app', '-h', '127.0.0.1', '-c', 'select 1'])->successful());

        // The volume as the SQLite app left it.
        $docker(['run', '--rm', '--user', 'root', '-v', "{$volume}:/data", '--entrypoint', 'bash', $image, '-c',
            'mkdir -p /data/workspace/database /data/storage && sqlite3 /data/workspace/database/database.sqlite "create table doses (id integer primary key autoincrement, mg int); insert into doses (mg) values (5), (10)"'
            .' && chown -R sandbox:sandbox /data && touch /data/.onedrop-seeded'])->throw();

        // The release: its migrate script makes the table in Postgres, as `php artisan migrate` would.
        $boot = 'mkdir -p /workspace/.onedrop'
            .' && printf \'#!/usr/bin/env bash\nset -e\npsql "$DATABASE_URL" -c "create table if not exists doses (id bigserial primary key, mg int)"\n\' > /workspace/.onedrop/migrate'
            .' && printf \'#!/usr/bin/env bash\nexec python3 -m http.server "$PORT"\n\' > /workspace/.onedrop/start'
            .' && chmod +x /workspace/.onedrop/migrate /workspace/.onedrop/start && exec /opt/onedrop-hosting/run run';
        $start = fn () => $docker(['run', '-d', '--name', $app, '--network', $network, '--user', 'root',
            '-v', base_path('docker/sandbox/hosting').':/opt/onedrop-hosting/run:ro', '-v', base_path('docker/sandbox/pg-import.php').':/opt/onedrop-hosting/pg-import.php:ro',
            '-v', "{$volume}:/data", '-e', 'ONEDROP_VOLUME=1', '-e', 'ONEDROP_DATA=database/database.sqlite',
            '-e', 'ONEDROP_IMPORT_SQLITE=database/database.sqlite', '-e', "DATABASE_URL={$url}",
            '--entrypoint', 'bash', $image, '-c', $boot])->throw();
        $logs = fn () => Process::run(['docker', 'logs', $app])->output();

        $start();
        until(fn () => str_contains($logs(), 'Starting the app'));

        expect($logs())->toContain("Moved the app's data into Postgres.")
            ->and($psql('select string_agg(mg::text, \',\' order by id) from doses'))->toBe('5,10');

        // Moved once: a restart doesn't copy the SQLite data over what the app wrote since.
        $psql('insert into doses (mg) values (20)');
        $docker(['rm', '-f', $app]);
        $start();
        until(fn () => str_contains($logs(), 'Starting the app'));

        expect($logs())->not->toContain('Moving the app')
            ->and($psql('select count(*) from doses'))->toBe('3');
    } finally {
        $docker(['rm', '-f', $app, $postgres]);
        $docker(['volume', 'rm', '-f', $volume]);
        $docker(['network', 'rm', $network]);
    }
})->group('HOST-009');
