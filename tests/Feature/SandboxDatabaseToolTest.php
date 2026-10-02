<?php

/*
 * docker/sandbox/db.php, run locally against a temporary workspace with a real SQLite database.
 */

beforeEach(function () {
    $this->workspace = databaseWorkspace();
    $this->connection = 'sqlite:database/database.sqlite';
    $this->tool = fn (array $request) => runDatabaseTool($this->workspace, ['connection' => $this->connection, ...$request]);
});

test('it finds databases from env files and sqlite files, without exposing credentials', function () {
    mkdir($this->workspace.'/api');
    file_put_contents($this->workspace.'/api/.env', "DATABASE_URL=\"postgresql://app:s3cret@db.example.com:6543/main?sslmode=require\"\n");
    mkdir($this->workspace.'/node_modules/pkg', recursive: true);
    copy($this->workspace.'/database/database.sqlite', $this->workspace.'/node_modules/pkg/ignored.db');
    copy($this->workspace.'/database/database.sqlite', $this->workspace.'/data.sqlite3');
    file_put_contents($this->workspace.'/not-sqlite.db', 'plain text');

    $response = runDatabaseTool($this->workspace, ['op' => 'connections']);

    expect($response['ok'])->toBeTrue()
        ->and(array_column($response['data'], 'id'))->toBe([
            'sqlite:database/database.sqlite',
            'env:api/.env:DATABASE_URL',
            'sqlite:data.sqlite3',
        ])
        ->and($response['data'][0])->toMatchArray(['driver' => 'sqlite', 'source' => '.env (DB_CONNECTION)', 'error' => null])
        ->and($response['data'][1])->toMatchArray(['driver' => 'pgsql', 'label' => 'PostgreSQL', 'summary' => 'app@db.example.com:6543/main'])
        ->and(json_encode($response))->not->toContain('s3cret');
})->group('DB-001');

test('a declared sqlite database that does not exist yet is reported', function () {
    file_put_contents($this->workspace.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=storage/app.sqlite\n");

    $response = runDatabaseTool($this->workspace, ['op' => 'connections']);

    expect($response['data'][0]['id'])->toBe('sqlite:storage/app.sqlite')
        ->and($response['data'][0]['error'])->toContain("doesn't exist yet");
})->group('DB-001');

test('it lists tables and views with their column names', function () {
    expect(($this->tool)(['op' => 'tables'])['data'])->toBe([
        ['name' => 'notes', 'type' => 'table', 'columns' => ['body']],
        ['name' => 'user_names', 'type' => 'view', 'columns' => ['name']],
        ['name' => 'users', 'type' => 'table', 'columns' => ['id', 'name', 'email', 'active', 'avatar']],
    ]);
})->group('DB-001');

test('it pages, sorts and filters rows, with column details and binary previews', function () {
    $page = ($this->tool)(['op' => 'rows', 'table' => 'users', 'per_page' => 2, 'page' => 1])['data'];

    expect($page['total'])->toBe(3)
        ->and(array_column($page['rows'], 'name'))->toBe(['Ann', 'Bob'])
        ->and($page['rows'][0]['avatar'])->toBe(['preview' => null, 'bytes' => 2])
        ->and($page['columns'][0])->toBe(['name' => 'id', 'type' => 'integer', 'nullable' => false, 'default' => null, 'primary' => true, 'auto' => true])
        ->and($page['columns'][1])->toMatchArray(['name' => 'name', 'nullable' => false, 'primary' => false]);

    $sorted = ($this->tool)(['op' => 'rows', 'table' => 'users', 'sort' => 'name', 'direction' => 'desc'])['data'];
    expect(array_column($sorted['rows'], 'name'))->toBe(['Cy_1', 'Bob', 'Ann']);

    // "%" and "_" match literally.
    $filter = fn (array ...$filters) => array_column(($this->tool)(['op' => 'rows', 'table' => 'users', 'filters' => $filters])['data']['rows'], 'name');
    expect($filter(['column' => 'email', 'operator' => 'contains', 'value' => '%@']))->toBe(['Cy_1'])
        ->and($filter(['column' => 'name', 'operator' => 'contains', 'value' => 'y_']))->toBe(['Cy_1'])
        ->and($filter(['column' => 'avatar', 'operator' => 'null'], ['column' => 'id', 'operator' => 'gte', 'value' => '3']))->toBe(['Cy_1'])
        ->and($filter(['column' => 'name', 'operator' => 'neq', 'value' => 'Ann']))->toBe(['Bob', 'Cy_1']);
})->group('DB-001');

test('it rejects unknown tables, columns and filters', function () {
    expect(($this->tool)(['op' => 'rows', 'table' => 'nope']))->toBe(['ok' => false, 'error' => "The table nope doesn't exist."])
        ->and(($this->tool)(['op' => 'rows', 'table' => 'users', 'sort' => 'id; drop table users'])['ok'])->toBeFalse()
        ->and(($this->tool)(['op' => 'rows', 'table' => 'users', 'filters' => [['column' => 'name', 'operator' => 'like']]])['error'])->toBe('That filter is not valid.')
        ->and(runDatabaseTool($this->workspace, ['op' => 'tables', 'connection' => 'sqlite:../etc.db'])['error'])->toContain("wasn't found");
})->group('DB-001');

test('it applies inserts, updates and deletes together', function () {
    $response = ($this->tool)([
        'op' => 'changes',
        'table' => 'users',
        'inserts' => [['name' => 'Dee', 'email' => '']],
        'updates' => [['key' => ['id' => 1], 'values' => ['name' => 'Anna', 'email' => null, 'active' => false]]],
        'deletes' => [['id' => 2]],
    ]);

    expect($response)->toBe(['ok' => true, 'data' => ['inserted' => 1, 'updated' => 1, 'deleted' => 1]]);

    $rows = ($this->tool)(['op' => 'rows', 'table' => 'users'])['data']['rows'];
    expect(array_column($rows, 'name'))->toBe(['Anna', 'Cy_1', 'Dee'])
        ->and($rows[0]['email'])->toBeNull()
        ->and($rows[0]['active'])->toBe(0)
        ->and($rows[2]['email'])->toBe('')
        ->and($rows[2]['active'])->toBe(1);
})->group('DB-001');

test('a failing change rolls back the whole save', function () {
    $response = ($this->tool)([
        'op' => 'changes',
        'table' => 'users',
        'updates' => [['key' => ['id' => 1], 'values' => ['name' => 'Anna']]],
        'inserts' => [['email' => 'no-name@example.com']],
    ]);

    expect($response['ok'])->toBeFalse()
        ->and($response['error'])->toContain('NOT NULL')
        ->and(($this->tool)(['op' => 'rows', 'table' => 'users'])['data']['rows'][0]['name'])->toBe('Ann');
})->group('DB-001');

test('views and existing rows of tables without a primary key cannot be changed', function () {
    expect(($this->tool)(['op' => 'changes', 'table' => 'user_names', 'inserts' => [['name' => 'x']]])['error'])->toContain('is a view')
        ->and(($this->tool)(['op' => 'changes', 'table' => 'notes', 'deletes' => [['body' => 'hello']]])['error'])->toContain('no primary key')
        ->and(($this->tool)(['op' => 'changes', 'table' => 'notes', 'inserts' => [['body' => 'more']]])['data']['inserted'])->toBe(1)
        ->and(($this->tool)(['op' => 'changes', 'table' => 'users', 'updates' => [['key' => ['name' => 'Ann'], 'values' => ['name' => 'x']]]])['error'])->toContain('primary key');
})->group('DB-001');

test('the sql runner returns rows, affected counts and database errors', function () {
    $select = ($this->tool)(['op' => 'query', 'sql' => 'select id, name from users order by id limit 2'])['data'];
    expect($select['columns'])->toBe(['id', 'name'])
        ->and($select['rows'])->toBe([[1, 'Ann'], [2, 'Bob']])
        ->and($select['affected'])->toBeNull()
        ->and($select['truncated'])->toBeFalse();

    expect(($this->tool)(['op' => 'query', 'sql' => "update users set active = 0 where name like 'B%'"])['data'])
        ->toMatchArray(['columns' => [], 'affected' => 1]);

    expect(($this->tool)(['op' => 'query', 'sql' => 'select * from missing'])['error'])->toContain('no such table: missing');
})->group('DB-001');

/**
 * A fake `docker` whose `ps` and `inspect` show the given containers, as the sandbox's own Docker would.
 *
 * @param  list<array<string, mixed>>  $containers
 */
function fakeDocker(string $workspace, array $containers): string
{
    $dir = $workspace.'/.fake-docker';
    mkdir($dir);
    file_put_contents($dir.'/inspect.json', json_encode($containers));
    // `docker exec <id> cat <path>` reads <path> under the fake's folder, standing in for the container's files.
    file_put_contents($dir.'/docker', "#!/bin/sh\necho \"\$*\" >> '{$dir}/calls'\ncase \"\$1\" in\nps) ".implode(' ', array_map(fn ($c) => "echo {$c['Id']};", $containers))." ;;\nexec) cat '{$dir}'\"\$4\" ;;\n*) cat '{$dir}/inspect.json' ;;\nesac\n");
    chmod($dir.'/docker', 0755);

    return $dir.'/docker';
}

/**
 * docker inspect output for one container of a compose stack.
 *
 * @param  list<string>  $env
 * @param  array<string, mixed>  $ports
 * @return array<string, mixed>
 */
function composeContainer(string $service, string $image, array $env, array $ports = []): array
{
    return [
        'Id' => bin2hex(random_bytes(8)),
        'Name' => "/supabase-{$service}",
        'Config' => ['Image' => $image, 'Env' => $env, 'Labels' => ['com.docker.compose.service' => $service]],
        'NetworkSettings' => [
            'Ports' => $ports,
            // Loopback, so connecting fails fast in tests; a real container has its compose network's address.
            'Networks' => ['supabase_default' => ['IPAddress' => '127.0.0.1', 'Aliases' => ["supabase-{$service}", $service]]],
        ],
    ];
}

test('it finds database containers in the sandbox docker, like supabase\'s db service', function () {
    $docker = fakeDocker($this->workspace, [
        composeContainer('db', 'supabase/postgres:15.8.1.060', ['POSTGRES_PASSWORD=s3cret', 'POSTGRES_DB=postgres', 'PGPORT=9']),
        composeContainer('studio', 'supabase/studio:2025.06.30', ['POSTGRES_PASSWORD=s3cret']),
        composeContainer('mysql', 'mariadb:11', ['MARIADB_ROOT_PASSWORD=r00t', 'MARIADB_DATABASE=shop'], ['3306/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '33060']]]),
    ]);

    $response = runDatabaseTool($this->workspace, ['op' => 'connections'], ['ONEDROP_DOCKER_BIN' => $docker]);

    expect(array_column($response['data'], 'id'))->toBe(['sqlite:database/database.sqlite', 'docker:supabase-db', 'docker:supabase-mysql'])
        ->and($response['data'][1])->toMatchArray(['driver' => 'pgsql', 'summary' => 'postgres@db:9/postgres', 'source' => 'Docker container supabase-db'])
        ->and($response['data'][2])->toMatchArray(['driver' => 'mysql', 'summary' => 'root@mysql:3306/shop'])
        ->and(json_encode($response))->not->toContain('s3cret')->not->toContain('r00t');

    // Reached at the container's address, or on the port it publishes to the sandbox.
    $error = fn (string $id) => runDatabaseTool($this->workspace, ['op' => 'tables', 'connection' => $id], ['ONEDROP_DOCKER_BIN' => $docker])['error'];
    expect($error('docker:supabase-db'))->toContain('"127.0.0.1", port 9 failed')
        ->and($error('docker:supabase-mysql'))->toContain("Couldn't connect");
})->group('DB-001');

test('an env file host that names a container is reached at that container', function () {
    file_put_contents($this->workspace.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=9\nDB_DATABASE=app\nDB_USERNAME=app\nDB_PASSWORD=pw\n");
    $docker = fakeDocker($this->workspace, [composeContainer('db', 'postgres:16-alpine', ['POSTGRES_PASSWORD=pw'])]);

    $response = runDatabaseTool($this->workspace, ['op' => 'connections'], ['ONEDROP_DOCKER_BIN' => $docker]);

    // One connection for the container, not a second one of its own.
    expect(array_column($response['data'], 'id'))->toBe(['env:.env:DB_CONNECTION', 'sqlite:database/database.sqlite'])
        ->and($response['data'][0]['summary'])->toBe('app@db:9/app')
        ->and(runDatabaseTool($this->workspace, ['op' => 'tables', 'connection' => 'env:.env:DB_CONNECTION'], ['ONEDROP_DOCKER_BIN' => $docker])['error'])->toContain('"127.0.0.1", port 9 failed');
})->group('DB-001');

test('it finds containers built on a database image, and reads credentials kept in docker secrets', function () {
    $docker = fakeDocker($this->workspace, [
        composeContainer('database', 'myapp-database', ['PG_MAJOR=16', 'POSTGRES_USER=app', 'POSTGRES_PASSWORD_FILE=/run/secrets/db_password', 'PGPORT=9']),
    ]);
    mkdir($this->workspace.'/.fake-docker/run/secrets', recursive: true);
    file_put_contents($this->workspace.'/.fake-docker/run/secrets/db_password', "from-a-secret\n");

    $response = runDatabaseTool($this->workspace, ['op' => 'connections'], ['ONEDROP_DOCKER_BIN' => $docker]);

    expect($response['data'][1])->toMatchArray(['id' => 'docker:supabase-database', 'driver' => 'pgsql', 'summary' => 'app@database:9/app'])
        ->and(json_encode($response))->not->toContain('from-a-secret')
        ->and(file_get_contents($this->workspace.'/.fake-docker/calls'))->toMatch('#^exec \w+ cat /run/secrets/db_password$#m');
})->group('DB-001');

test('it finds database urls under any name, and postgres, mysql and node-style settings', function () {
    mkdir($this->workspace.'/app');
    $env = fn () => $this->workspace.'/app/.env';

    file_put_contents($env(), "SUPABASE_DB_URL=postgresql://postgres:pw@db.abc.supabase.co:5432/postgres\nDIRECT_URL=postgresql://postgres:pw@db.abc.supabase.co:5432/postgres\nREDIS_URL=redis://cache:6379\n");
    // Besides the workspace's own SQLite database.
    $found = fn () => array_map(fn ($c) => [$c['id'], $c['driver'], $c['summary']], array_slice(runDatabaseTool($this->workspace, ['op' => 'connections'])['data'], 1));

    // The same URL under two names is one connection.
    expect($found())->toBe([['env:app/.env:SUPABASE_DB_URL', 'pgsql', 'postgres@db.abc.supabase.co:5432/postgres']]);

    file_put_contents($env(), "PGHOST=pg.internal\nPGUSER=reader\nPGDATABASE=reports\nMYSQL_HOST=mysql\nMYSQL_USER=shop\nMYSQL_PASSWORD=pw\nMYSQL_DATABASE=shop\n");
    expect($found())->toBe([
        ['env:app/.env:PGHOST', 'pgsql', 'reader@pg.internal:5432/reports'],
        ['env:app/.env:MYSQL_HOST', 'mysql', 'shop@mysql:3306/shop'],
    ]);

    // Supabase's own .env: POSTGRES_HOST names the db service; a socket path is skipped.
    file_put_contents($env(), "POSTGRES_HOST=db\nPOSTGRES_DB=postgres\nPOSTGRES_PORT=5432\nPOSTGRES_PASSWORD=pw\n");
    expect($found())->toBe([['env:app/.env:POSTGRES_HOST', 'pgsql', 'postgres@db:5432/postgres']]);
    file_put_contents($env(), "POSTGRES_HOST=/var/run/postgresql\n");
    expect($found())->toBe([]);

    // Node apps: DB_HOST/DB_USER/DB_PASS/DB_NAME, the driver from DB_DIALECT or the port.
    file_put_contents($env(), "DB_DIALECT=postgres\nDB_HOST=db\nDB_USER=node\nDB_PASS=pw\nDB_NAME=api\n");
    expect($found())->toBe([['env:app/.env:DB_HOST', 'pgsql', 'node@db:5432/api']]);
    file_put_contents($env(), "DB_HOST=db\nDB_PORT=3306\nDB_USER=node\nDB_NAME=api\n");
    expect($found())->toBe([['env:app/.env:DB_HOST', 'mysql', 'node@db:3306/api']]);
})->group('DB-001');

test('an env file that names a container with the port it publishes reaches that port', function () {
    file_put_contents($this->workspace.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=9\nDB_DATABASE=app\n");
    // Published on 9, so it's reached at 127.0.0.1:9 rather than its own address (one that would never answer).
    $container = composeContainer('db', 'postgres:16', [], ['5432/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '9']]]);
    $container['NetworkSettings']['Networks']['supabase_default']['IPAddress'] = '10.255.255.1';
    $docker = fakeDocker($this->workspace, [$container]);

    $error = runDatabaseTool($this->workspace, ['op' => 'tables', 'connection' => 'env:.env:DB_CONNECTION'], ['ONEDROP_DOCKER_BIN' => $docker])['error'];

    expect($error)->toContain('"127.0.0.1", port 9 failed');
})->group('DB-001');
