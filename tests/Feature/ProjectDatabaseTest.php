<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->provider = fakeDatabaseSandbox(databaseWorkspace());
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->connection = 'sqlite:database/database.sqlite';
});

test('the owner can list databases, tables and rows', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.database.connections', $this->project))
        ->assertOk()
        ->assertJsonPath('connections.0.id', $this->connection)
        ->assertJsonPath('connections.0.label', 'SQLite');

    $this->actingAs($this->user)
        ->getJson(route('projects.database.tables', [$this->project, 'connection' => $this->connection]))
        ->assertOk()
        ->assertJsonPath('tables.2', ['name' => 'users', 'type' => 'table', 'columns' => ['id', 'name', 'email', 'active', 'avatar']]);

    $this->actingAs($this->user)
        ->getJson(route('projects.database.rows', [
            $this->project,
            'connection' => $this->connection,
            'table' => 'users',
            'sort' => 'name',
            'direction' => 'desc',
            'per_page' => 2,
            'filters' => [['column' => 'email', 'operator' => 'contains', 'value' => 'example']],
        ]))
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('rows.0.name', 'Cy_1')
        ->assertJsonCount(2, 'rows');

    expect($this->provider->executed[0])->toMatchArray(['id' => 'ctr-1', 'command' => ['php', '/opt/onedrop/db.php']]);
})->group('DB-001');

test('saving changes keeps empty strings and spaces as typed', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.database.change', $this->project), [
            'connection' => $this->connection,
            'table' => 'users',
            'inserts' => [['name' => ' Dee ', 'email' => '']],
            'updates' => [['key' => ['id' => 1], 'values' => ['email' => null]]],
            'deletes' => [['id' => 2]],
        ])
        ->assertOk()
        ->assertExactJson(['inserted' => 1, 'updated' => 1, 'deleted' => 1]);

    $this->actingAs($this->user)
        ->getJson(route('projects.database.rows', [$this->project, 'connection' => $this->connection, 'table' => 'users']))
        ->assertJsonPath('rows.0.email', null)
        ->assertJsonPath('rows.2.name', ' Dee ')
        ->assertJsonPath('rows.2.email', '');
})->group('DB-001');

test('the sql runner runs a statement and shows database errors', function () {
    $this->actingAs($this->user)
        ->postJson(route('projects.database.query', $this->project), ['connection' => $this->connection, 'sql' => 'select count(*) as n from users'])
        ->assertOk()
        ->assertJsonPath('columns', ['n'])
        ->assertJsonPath('rows', [[3]]);

    $this->actingAs($this->user)
        ->postJson(route('projects.database.query', $this->project), ['connection' => $this->connection, 'sql' => 'select * from missing'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'SQLSTATE[HY000]: General error: 1 no such table: missing');
})->group('DB-001');

test('requests are validated', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.database.rows', [$this->project, 'connection' => $this->connection, 'table' => 'users', 'filters' => [['column' => 'id', 'operator' => 'like']]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filters.0.operator');

    $this->actingAs($this->user)
        ->postJson(route('projects.database.change', $this->project), ['connection' => $this->connection, 'table' => 'users', 'updates' => [['key' => ['id' => 1]]]])
        ->assertJsonValidationErrors('updates.0.values');

    $this->actingAs($this->user)
        ->postJson(route('projects.database.query', $this->project), ['connection' => $this->connection])
        ->assertJsonValidationErrors('sql');
})->group('DB-001');

test('other users cannot read or change the database', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)->getJson(route('projects.database.connections', $this->project))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('projects.database.rows', [$this->project, 'connection' => $this->connection, 'table' => 'users']))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.database.query', $this->project), ['connection' => $this->connection, 'sql' => 'delete from users'])->assertForbidden();
    $this->actingAs($stranger)->postJson(route('projects.database.change', $this->project), ['connection' => $this->connection, 'table' => 'users', 'deletes' => [['id' => 1]]])->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->group('DB-001');

test('it explains a stopped sandbox and a sandbox without the database tool', function () {
    $this->sandbox->update(['status' => SandboxStatus::Paused]);
    $this->actingAs($this->user)->getJson(route('projects.database.connections', $this->project))->assertStatus(409);

    $this->sandbox->update(['status' => SandboxStatus::Running]);
    $old = new FakeSandboxProvider;
    $old->execUsing = fn () => new ExecResult(1, 'Could not open input file: /opt/onedrop/db.php');
    app()->instance(SandboxProvider::class, $old);

    $this->actingAs($this->user)
        ->getJson(route('projects.database.connections', $this->project))
        ->assertStatus(502)
        ->assertJsonPath('message', "This sandbox doesn't have the database tool yet. Rebuild the sandbox image and recreate the sandbox.");
})->group('DB-001');

/**
 * The fake sandbox's database tool, plus the project's AI answering $answer through OpenCode (DB-003).
 */
function withAiAnswer(FakeSandboxProvider $provider, string $answer): FakeSandboxProvider
{
    $database = $provider->execUsing;
    $provider->execUsing = fn (array $command, array $env) => str_contains($command[2] ?? '', 'opencode run')
        ? new ExecResult(0, json_encode(['type' => 'text', 'part' => ['text' => $answer]]))
        : $database($command, $env);

    return $provider;
}

test('the ai writes a query from a plain-words request, knowing the tables and the current query', function () {
    withAiAnswer($this->provider, "Here it is:\n```sql\nselect * from users where lower(name) like '%ann%' limit 50\n```\nThis finds Ann.");

    $this->actingAs($this->user)
        ->postJson(route('projects.database.write-query', $this->project), [
            'connection' => $this->connection,
            'driver' => 'sqlite',
            'request' => 'people called ann',
            'current' => 'select * from users',
        ])
        ->assertOk()
        ->assertJson(['query' => "select * from users where lower(name) like '%ann%' limit 50"]);

    $prompt = collect($this->provider->executed)->first(fn ($call) => str_contains($call['command'][2] ?? '', 'opencode run'))['env']['APP_PROMPT'];

    expect($prompt)->toContain('one SQLite SQL statement')
        ->toContain('people called ann')
        ->toContain('users: id, name, email, active, avatar')
        ->toContain('user_names (view): name')
        ->toContain("<current-query>\nselect * from users\n</current-query>")
        // The rows never go to the AI.
        ->not->toContain('ann@example.com');
})->group('DB-003');

test('a mongodb request asks for mongosh syntax, and an answer without a code block is used whole', function () {
    withAiAnswer($this->provider, "db.users.find({ name: /ann/i }).limit(50)\n");

    $this->actingAs($this->user)
        ->postJson(route('projects.database.write-query', $this->project), ['connection' => $this->connection, 'driver' => 'mongodb', 'request' => 'ann'])
        ->assertOk()
        ->assertJson(['query' => 'db.users.find({ name: /ann/i }).limit(50)']);

    $prompt = collect($this->provider->executed)->first(fn ($call) => str_contains($call['command'][2] ?? '', 'opencode run'))['env']['APP_PROMPT'];

    expect($prompt)->toContain('mongosh syntax')->toContain('use database')->not->toContain('<current-query>');
})->group('DB-003');

test('writing a query explains when the ai gives no query or can\'t be asked, and needs edit access', function () {
    withAiAnswer($this->provider, "```\n```");

    $this->actingAs($this->user)
        ->postJson(route('projects.database.write-query', $this->project), ['connection' => $this->connection, 'driver' => 'sqlite', 'request' => 'x'])
        ->assertStatus(502)
        ->assertJson(['message' => "The AI didn't write a query. Try describing it another way."]);

    $database = $this->provider->execUsing;
    $this->provider->execUsing = fn (array $command, array $env) => str_contains($command[2] ?? '', 'opencode run')
        ? new ExecResult(1, '', "Error: model not found\nmore detail")
        : $database($command, $env);

    $this->actingAs($this->user)
        ->postJson(route('projects.database.write-query', $this->project), ['connection' => $this->connection, 'driver' => 'sqlite', 'request' => 'x'])
        ->assertStatus(502)
        ->assertJson(['message' => 'Error: model not found']);

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->postJson(route('projects.database.write-query', $this->project), ['connection' => $this->connection, 'driver' => 'sqlite', 'request' => 'x'])
        ->assertForbidden();
})->group('DB-003');
