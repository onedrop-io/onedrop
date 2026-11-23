<?php

use Illuminate\Support\Facades\DB;

/**
 * Two connections to one WAL-mode file, as the dev server and several queue workers share database.sqlite.
 */
function sharedSqliteConnections(string $path): array
{
    touch($path);

    foreach (['first', 'second'] as $name) {
        config(["database.connections.{$name}" => array_merge(config('database.connections.sqlite'), ['database' => $path, 'busy_timeout' => 2000])]);
    }

    DB::connection('first')->statement('pragma journal_mode = wal');
    DB::connection('first')->statement('create table jobs (id integer primary key, reserved_at integer)');
    DB::connection('first')->table('jobs')->insert(['id' => 1]);

    return [DB::connection('first'), DB::connection('second')];
}

test('a transaction that reads and then writes waits for another writer instead of failing at once', function () {
    $path = tempnam(sys_get_temp_dir(), 'sqlite-lock-');
    [$worker, $other] = sharedSqliteConnections($path);

    // A queue worker reserving a job: read it, another process writes meanwhile, then mark it reserved.
    $worker->transaction(function () use ($worker, $other) {
        $worker->table('jobs')->first();

        try {
            $other->table('jobs')->update(['reserved_at' => 1]);
        } catch (Throwable) {
            // Blocked by the worker's write lock: the worker can't be the one that fails.
        }

        $worker->table('jobs')->where('id', 1)->update(['reserved_at' => 2]);
    });

    expect($worker->table('jobs')->value('reserved_at'))->toBe(2);

    DB::purge('first');
    DB::purge('second');
    @unlink($path);
})->group('GIT-004');
