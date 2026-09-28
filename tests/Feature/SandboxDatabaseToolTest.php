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
