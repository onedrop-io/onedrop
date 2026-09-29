<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->workspace = databaseWorkspace();
    app()->instance(SandboxProvider::class, fakeDatabaseSandbox($this->workspace));

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

/**
 * @return list<array<string, mixed>>
 */
function workspaceUsers(string $workspace): array
{
    return runDatabaseTool($workspace, ['op' => 'rows', 'connection' => 'sqlite:database/database.sqlite', 'table' => 'users'])['data']['rows'];
}

test('the user can browse, edit, add and delete rows', function () {
    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertVisible('@database-panel')
        ->assertSeeIn('@db-tables', 'users')
        ->assertSeeIn('@db-tables', 'user_names')
        ->click('@db-table-users')
        ->assertSeeIn('@db-row-count', '3 rows')
        ->assertSeeIn('@db-column-email', 'text')
        ->assertSeeIn('@db-cell-0-avatar', 'binary')

        // Edit a cell, set another to NULL: both stay pending until saved.
        ->keys('@db-cell-0-name', 'Enter')
        ->type('@db-cell-input', 'Anna')
        ->keys('@db-cell-input', 'Enter')
        ->keys('@db-cell-1-email', 'Enter')
        ->click('@db-set-null')
        ->assertSeeIn('@db-cell-1-email', 'NULL')
        ->assertSeeIn('@db-pending', '2 unsaved changes');

    expect(workspaceUsers($this->workspace)[0]['name'])->toBe('Ann');

    $page->click('@db-save')
        ->assertMissing('@db-pending')
        ->assertSeeIn('@db-cell-0-name', 'Anna');

    expect(workspaceUsers($this->workspace)[0]['name'])->toBe('Anna')
        ->and(workspaceUsers($this->workspace)[1]['email'])->toBeNull();

    // Add a row and delete another in one save.
    $page->click('@db-add-row')
        ->keys('@db-new-cell-0-name', 'Enter')
        ->type('@db-cell-input', 'Dee')
        ->keys('@db-cell-input', 'Enter')
        ->click('[aria-label="Select row 3"]')
        ->click('@db-delete-selected')
        ->assertSeeIn('@db-pending', '2 unsaved changes')
        ->click('@db-save')
        ->assertMissing('@db-pending')
        ->assertSeeIn('@db-row-count', '3 rows');

    expect(array_column(workspaceUsers($this->workspace), 'name'))->toBe(['Anna', 'Bob', 'Dee']);

    $page->assertNoJavaScriptErrors();
})->group('DB-001');

test('the user can filter and sort rows and run sql with autocomplete', function () {
    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->click('@db-table-users')
        ->assertSeeIn('@db-row-count', '3 rows')
        ->click('@db-column-name')
        ->click('@db-column-name')
        ->assertSeeIn('@db-cell-0-name', 'Cy_1')
        ->click('@db-filters-toggle')
        ->select('[aria-label="Filter column"]', 'email')
        ->select('[aria-label="Filter operator"]', 'contains')
        ->type('@db-filter-value', 'bob')
        ->click('@db-apply-filters')
        ->assertSeeIn('@db-row-count', '1 row')
        ->assertSeeIn('@db-cell-0-name', 'Bob')

        ->click('@db-open-sql')
        ->clear('@db-sql')
        ->typeSlowly('@db-sql', 'select email from users where act', 20)
        ->assertSeeIn('.cm-tooltip-autocomplete', 'active')
        ->wait(0.2)
        ->keys('@db-sql', ['Enter', 'ControlOrMeta+Enter'])
        ->assertSeeIn('@db-sql-summary', '3 rows')
        ->assertSeeIn('@db-sql-result', 'bob@example.com')
        ->clear('@db-sql')
        ->type('@db-sql', 'select name from users where id > 1 order by id')
        ->click('@db-run')
        ->assertSeeIn('@db-sql-summary', '2 rows')
        ->assertSeeIn('@db-sql-result', 'Cy_1')
        ->clear('@db-sql')
        ->type('@db-sql', 'select * from missing')
        ->click('@db-run')
        ->assertSeeIn('@db-sql-error', 'no such table: missing')
        ->assertNoJavaScriptErrors();
})->group('DB-001');

test('in a narrow pane the tables are picked from a dropdown', function () {
    visit("/projects/{$this->project->id}")
        ->resize(1280, 900)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertMissing('@db-tables')
        ->select('@db-table-select', 'table:users')
        ->assertSeeIn('@db-row-count', '3 rows')
        ->select('@db-table-select', 'sql')
        ->assertVisible('@db-sql-runner')
        ->assertNoJavaScriptErrors();
})->group('DB-001');

test('the database section explains when no database is found', function () {
    unlink($this->workspace.'/.env');
    unlink($this->workspace.'/database/database.sqlite');

    visit("/projects/{$this->project->id}")
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertSeeIn('@database-empty', 'No database found yet')
        ->assertNoJavaScriptErrors();
})->group('DB-001');

test('the database section explains when the sandbox is not running', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $this->project->sandbox->update(['status' => 'paused']);

    visit("/projects/{$this->project->id}")
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertSeeIn('@database-empty', 'works when the sandbox is running')
        ->assertNoJavaScriptErrors();
})->group('DB-001');

test('a cell can be set to the text "cancel", and Escape still cancels', function () {
    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->click('@db-table-users')
        ->keys('@db-cell-0-name', 'Enter')
        ->type('@db-cell-input', 'Nobody')
        ->keys('@db-cell-input', 'Escape')
        ->assertMissing('@db-pending')
        ->keys('@db-cell-0-name', 'Enter')
        ->type('@db-cell-input', 'cancel')
        ->keys('@db-cell-input', 'Enter')
        ->assertSeeIn('@db-pending', '1 unsaved change')
        ->click('@db-save')
        ->assertMissing('@db-pending');

    expect(workspaceUsers($this->workspace)[0]['name'])->toBe('cancel');

    $page->assertNoJavaScriptErrors();
})->group('DB-001');
