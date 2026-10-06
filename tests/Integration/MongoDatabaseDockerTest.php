<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
 * docker/sandbox/db.php in the sandbox image, against real MongoDB servers (DB-002).
 * Opt in with: RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration. Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }

    $id = bin2hex(random_bytes(3));
    $this->network = "onedrop-mongo-{$id}";
    $this->standalone = "onedrop-mongo-standalone-{$id}";
    $this->replicaSet = "onedrop-mongo-rs-{$id}";
    $this->sandbox = "onedrop-mongo-sandbox-{$id}";
    $this->docker = fn (array $command, int $timeout = 120) => Process::timeout($timeout)->run(['docker', ...$command]);
    $docker = $this->docker;

    $docker(['network', 'create', $this->network])->throw();
    $docker(['run', '-d', '--name', $this->standalone, '--network', $this->network, 'mongo:8'])->throw();
    $docker(['run', '-d', '--name', $this->replicaSet, '--network', $this->network, 'mongo:8', '--replSet', 'rs0'])->throw();
    $docker(['run', '-d', '--name', $this->sandbox, '--network', $this->network, '-v', base_path('docker/sandbox/db.php').':/opt/onedrop/db.php:ro', config('sandbox.providers.docker.image'), 'sleep', '600'])->throw();

    $this->mongosh = fn (string $container, string $script) => $docker(['exec', $container, 'mongosh', '--quiet', '--eval', $script])->throw()->output();
    $until = function (Closure $check): void {
        foreach (range(1, 60) as $_) {
            if ($check()) {
                return;
            }

            usleep(500_000);
        }

        throw new RuntimeException('MongoDB did not start.');
    };
    $until(fn () => $docker(['exec', $this->standalone, 'mongosh', '--quiet', '--eval', 'db.runCommand({ ping: 1 })'])->successful());
    $until(fn () => $docker(['exec', $this->replicaSet, 'mongosh', '--quiet', '--eval', 'db.runCommand({ ping: 1 })'])->successful());
    ($this->mongosh)($this->replicaSet, "rs.initiate({ _id: 'rs0', members: [{ _id: 0, host: '{$this->replicaSet}:27017' }] })");
    $until(fn () => str_contains(($this->mongosh)($this->replicaSet, 'db.hello().isWritablePrimary'), 'true'));

    ($this->mongosh)($this->standalone, <<<'JS'
        const shop = db.getSiblingDB('shop');
        shop.users.insertMany([
            { _id: ObjectId('65a000000000000000000001'), name: 'Ann', age: 30, tags: ['a'], joined: ISODate('2026-01-02T00:00:00Z'), meta: { x: 1 } },
            { _id: ObjectId('65a000000000000000000002'), name: 'Bob', age: '41', score: 2.5 },
        ]);
        shop.createView('adults', 'users', [{ $match: { age: { $gte: 18 } } }]);
        db.getSiblingDB('blog').posts.insertOne({ _id: 1, title: 'Hi' });
        JS);

    $this->tool = function (array $request, string $env = '') use ($docker): array {
        $docker(['exec', $this->sandbox, 'sh', '-c', 'mkdir -p /tmp/ws && printf %s "$1" > /tmp/ws/.env', 'sh', $env ?: "MONGO_URL=mongodb://{$this->standalone}:27017\n"])->throw();
        $result = $docker(['exec', '-e', 'APP_WORKSPACE=/tmp/ws', '-e', 'APP_DB_REQUEST='.json_encode($request), $this->sandbox, 'php', '/opt/onedrop/db.php'])->throw();

        return json_decode($result->output(), true);
    };
    $this->query = fn (string $text) => ($this->tool)(['op' => 'query', 'connection' => 'env:.env:MONGO_URL', 'sql' => $text]);
});

afterEach(function () {
    if (isset($this->docker)) {
        ($this->docker)(['rm', '-f', $this->standalone, $this->replicaSet, $this->sandbox]);
        ($this->docker)(['network', 'rm', $this->network]);
    }
});

test('it browses every database on a server as database.collection, documents as typed rows', function () {
    $connection = ['connection' => 'env:.env:MONGO_URL'];

    expect(($this->tool)(['op' => 'connections'])['data'][0])->toMatchArray(['driver' => 'mongodb', 'label' => 'MongoDB', 'error' => null])
        ->and(array_map(fn ($table) => [$table['name'], $table['type'], $table['columns']], ($this->tool)(['op' => 'tables', ...$connection])['data']))->toBe([
            ['blog.posts', 'table', ['_id', 'title']],
            ['shop.adults', 'view', []],
            ['shop.users', 'table', ['_id', 'name', 'age', 'tags', 'joined', 'meta', 'score']],
        ]);

    $page = ($this->tool)(['op' => 'rows', ...$connection, 'table' => 'shop.users'])['data'];

    expect($page['total'])->toBe(2)
        ->and(array_map(fn ($column) => [$column['name'], $column['type'], $column['primary']], $page['columns']))->toBe([
            ['_id', 'objectId', true], ['name', 'string', false], ['age', 'int | string', false], ['tags', 'array', false],
            ['joined', 'date', false], ['meta', 'object', false], ['score', 'double', false],
        ])
        ->and($page['rows'][0])->toBe(['_id' => '65a000000000000000000001', 'name' => 'Ann', 'age' => 30, 'tags' => '["a"]', 'joined' => '2026-01-02T00:00:00.000Z', 'meta' => '{"x":1}'])
        // A missing field is left out, so the grid can tell it from null.
        ->and($page['rows'][1])->not->toHaveKey('tags');

    $names = fn (array ...$filters) => array_column(($this->tool)(['op' => 'rows', ...$connection, 'table' => 'shop.users', 'filters' => $filters])['data']['rows'], 'name');

    // "41" finds the string; "30" the number; an ObjectId's hex the ObjectId.
    expect($names(['column' => 'age', 'operator' => 'eq', 'value' => '41']))->toBe(['Bob'])
        ->and($names(['column' => 'age', 'operator' => 'eq', 'value' => '30']))->toBe(['Ann'])
        ->and($names(['column' => '_id', 'operator' => 'eq', 'value' => '65a000000000000000000002']))->toBe(['Bob'])
        ->and($names(['column' => 'joined', 'operator' => 'gte', 'value' => '2026-01-01']))->toBe(['Ann'])
        ->and($names(['column' => 'name', 'operator' => 'contains', 'value' => 'bo']))->toBe(['Bob'])
        ->and($names(['column' => 'score', 'operator' => 'null']))->toBe(['Ann'])
        ->and(array_column(($this->tool)(['op' => 'rows', ...$connection, 'table' => 'shop.users', 'sort' => 'name', 'direction' => 'desc'])['data']['rows'], 'name'))->toBe(['Bob', 'Ann']);
})->group('DB-002');

test('saved edits keep each field\'s type, and a standalone server says what was saved when a change fails', function () {
    $connection = ['connection' => 'env:.env:MONGO_URL'];

    $saved = ($this->tool)([
        'op' => 'changes', ...$connection, 'table' => 'shop.users',
        'inserts' => [['name' => 'Cy', 'age' => '7', 'joined' => '2026-03-04', 'extra' => '{ a: 1 }']],
        'updates' => [['key' => ['_id' => '65a000000000000000000001'], 'values' => ['age' => '31', 'joined' => '2026-02-01T10:00:00Z', 'meta' => '{"x": 2}', 'name' => null]]],
        'deletes' => [['_id' => '65a000000000000000000002']],
    ]);

    expect($saved)->toBe(['ok' => true, 'data' => ['inserted' => 1, 'updated' => 1, 'deleted' => 1]])
        ->and(trim(($this->mongosh)($this->standalone, <<<'JS'
            db.getSiblingDB('shop').users.find().sort({ _id: 1 }).toArray()
                .map(u => [typeof u.name, u.name, u.age, u.joined instanceof Date, u.meta && u.meta.x, u.extra && u.extra.a].join(':')).join(',')
            JS)))->toBe('object::31:true:2:,string:Cy:7:true::1');

    expect(($this->tool)(['op' => 'changes', ...$connection, 'table' => 'shop.users', 'updates' => [['key' => ['_id' => '65a000000000000000000001'], 'values' => ['age' => 'old']]]])['error'])
        ->toBe('age: "old" isn\'t a whole number.')
        ->and(($this->tool)(['op' => 'changes', ...$connection, 'table' => 'shop.adults', 'inserts' => [[]]])['error'])->toContain('is a view');

    // The first insert is kept; the duplicate _id stops the rest.
    expect(($this->tool)(['op' => 'changes', ...$connection, 'table' => 'blog.posts', 'inserts' => [['title' => 'Two'], ['_id' => '1', 'title' => 'Dup']]])['error'])
        ->toContain('duplicate key')
        ->toContain('This MongoDB server has no transactions, so the change before it was saved.')
        ->and(trim(($this->mongosh)($this->standalone, "db.getSiblingDB('blog').posts.countDocuments()")))->toBe('2');
})->group('DB-002');

test('on a replica set a save is all or nothing', function () {
    ($this->mongosh)($this->replicaSet, "db.getSiblingDB('app').posts.insertOne({ _id: 1, title: 'Hi' })");
    $env = "MONGODB_URI=mongodb://{$this->replicaSet}:27017/app?replicaSet=rs0\n";
    $change = fn (array $changes) => ($this->tool)(['op' => 'changes', 'connection' => 'env:.env:MONGODB_URI', 'table' => 'posts', ...$changes], $env);

    expect($change(['inserts' => [['title' => 'Two'], ['_id' => '1', 'title' => 'Dup']]])['error'])->toContain('duplicate key')->not->toContain('were saved')
        ->and(trim(($this->mongosh)($this->replicaSet, "db.getSiblingDB('app').posts.countDocuments()")))->toBe('1')
        ->and($change(['inserts' => [['title' => 'Two']], 'deletes' => [['_id' => 1]]])['data'])->toBe(['inserted' => 1, 'updated' => 0, 'deleted' => 1]);
})->group('DB-002');

test('the query runner runs mongosh-style commands', function () {
    $rows = fn (string $text) => ($this->query)($text)['data']['rows'] ?? ($this->query)($text)['error'];
    $affected = fn (string $text) => ($this->query)($text)['data']['affected'] ?? ($this->query)($text)['error'];

    expect(($this->query)('db.users.find()')['error'])->toContain('use <database>')
        ->and(array_column($rows('show dbs'), 0))->toContain('shop', 'blog')
        ->and($rows("use shop\ndb.users.find({ age: { \$gte: 18 } }, { name: 1, _id: 0 }).sort({ name: -1 }).limit(5)"))->toBe([['Ann']])
        ->and($rows("use shop\ndb.users.find({ name: /^b/i }).count()"))->toBe([[1]])
        ->and($rows("use shop\ndb.users.aggregate([{ \$match: { score: { \$exists: true } } }, { \$project: { _id: 0, score: 1 } }])"))->toBe([[2.5]])
        ->and($rows("use shop\ndb.users.distinct('name')"))->toBe([['Ann'], ['Bob']])
        ->and($rows("db.getSiblingDB('blog').posts.find()"))->toBe([[1, 'Hi']])
        ->and($rows('db.adminCommand({ ping: 1 })'))->toBe([[1.0]])
        ->and($rows("use shop\n{ count: 'users' }")[0][0])->toBe(2)
        ->and($rows("use shop\nshow collections"))->toBe([['adults', 'view'], ['users', 'collection']]);

    expect($affected("use shop\ndb.users.insertOne({ _id: ObjectId('65a000000000000000000009'), n: NumberLong('9007199254740993'), d: NumberDecimal('1.10'), at: ISODate('2026-05-01') })"))->toBe(1)
        ->and($rows("use shop\ndb.getCollection('users').findOne({ _id: { \$oid: '65a000000000000000000009' } }, { _id: 0 })"))->toBe([['9007199254740993', '1.10', '2026-05-01T00:00:00.000Z']])
        ->and($affected("use shop\ndb.users.updateMany({ name: { \$exists: true } }, { \$set: { seen: true } })"))->toBe(2)
        ->and($affected("use shop\ndb.users.deleteOne({ _id: ObjectId('65a000000000000000000009') })"))->toBe(1)
        ->and(($this->query)("use shop\ndb.users.drop()")['error'])->toContain("drop() isn't supported here")
        ->and(($this->query)("use shop\ndb.users.find({ a: 1 )")['error'])->toContain('Expected "," or "}"');
})->group('DB-002');
