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

    expect($this->provider->executed[0])->toMatchArray(['id' => 'ctr-1', 'command' => ['php', '/opt/zap/db.php']]);
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
    $old->execUsing = fn () => new ExecResult(1, 'Could not open input file: /opt/zap/db.php');
    app()->instance(SandboxProvider::class, $old);

    $this->actingAs($this->user)
        ->getJson(route('projects.database.connections', $this->project))
        ->assertStatus(502)
        ->assertJsonPath('message', "This sandbox doesn't have the database tool yet. Rebuild the sandbox image and recreate the sandbox.");
})->group('DB-001');
