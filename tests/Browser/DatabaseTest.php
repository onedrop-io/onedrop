<?php

use App\Enums\HostedServiceKind;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\HostedService;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

test("on a hosted project, the user switches to the hosted app's live database", function () {
    $hosted = databaseWorkspace();
    // The hosted copy has a fourth user the sandbox doesn't.
    (new PDO('sqlite:'.$hosted.'/database/database.sqlite'))->exec("INSERT INTO users (name, email) VALUES ('Dee', 'dee@example.com')");
    config(['hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-token', 'org_slug' => 'onedrop', 'region' => 'iad']]);
    Http::fake([
        'api.machines.dev/v1/apps/*/machines/m_app/exec' => fn (Request $request) => Http::response(runHostedDatabaseCommand($hosted, $request['command'])),
        'api.machines.dev/v1/apps/*/machines/m_app' => Http::response(['state' => 'started', 'config' => []]),
    ]);
    $this->project->update(['publish_target' => PublishTarget::Hosting, 'publish_status' => PublishStatus::Live, 'publish_visibility' => PublishVisibility::Public, 'published_url' => 'https://x.fly.dev']);
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::App, 'details' => ['machine' => 'm_app']]);

    visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->click('@db-table-users')
        ->assertSeeIn('@db-row-count', '3 rows')
        ->click('@db-location-hosted')
        ->assertSeeIn('@db-hosted-note', 'live data')
        ->click('@db-table-users')
        ->assertSeeIn('@db-row-count', '4 rows')
        ->assertVisible('@db-download')
        ->click('@db-location-sandbox')
        ->assertMissing('@db-hosted-note')
        ->assertNoJavaScriptErrors();
})->group('HOST-007');

/**
 * A sandbox whose database tool answers like docker/sandbox/db.php does for a MongoDB server (DB-002), whose real
 * answers tests/Integration/MongoDatabaseDockerTest.php checks. The requests it gets are kept in $log.
 */
function fakeMongoSandbox(string $log): FakeSandboxProvider
{
    $provider = new FakeSandboxProvider;
    $provider->execUsing = function (array $command, array $env) use ($log) {
        if ($command !== ['php', WorkspaceDatabase::SCRIPT]) {
            return new ExecResult(0, '');
        }

        $request = json_decode($env['APP_DB_REQUEST'], true);
        file_put_contents($log, json_encode($request)."\n", FILE_APPEND);
        $column = fn (string $name, string $type) => ['name' => $name, 'type' => $type, 'nullable' => $name !== '_id', 'default' => null, 'primary' => $name === '_id', 'auto' => $name === '_id'];

        $data = match ($request['op'] ?? null) {
            'connections' => [['id' => 'docker:workspace-mongo-1', 'driver' => 'mongodb', 'label' => 'MongoDB', 'summary' => 'mongo:27017', 'source' => 'Docker container workspace-mongo-1', 'error' => null]],
            'tables' => [
                ['name' => 'shop.orders', 'type' => 'table', 'columns' => ['_id', 'total'], 'database' => 'shop', 'collection' => 'orders'],
                ['name' => 'shop.users', 'type' => 'table', 'columns' => ['_id', 'name', 'age'], 'database' => 'shop', 'collection' => 'users'],
            ],
            'rows' => ['table' => 'shop.users', 'type' => 'table', 'columns' => [$column('_id', 'objectId'), $column('name', 'string'), $column('age', 'int')], 'rows' => [
                ['_id' => '65a000000000000000000001', 'name' => 'Ann', 'age' => 30],
                ['_id' => '65a000000000000000000002', 'name' => 'Bob'],
            ], 'total' => 2, 'page' => 1, 'per_page' => 50],
            'changes' => ['inserted' => 0, 'updated' => 2, 'deleted' => 0],
            'query' => ['columns' => ['_id', 'name'], 'rows' => [['65a000000000000000000001', 'Ann']], 'truncated' => false, 'affected' => null, 'duration_ms' => 1.2],
            default => null,
        };

        return new ExecResult(0, json_encode(['ok' => true, 'data' => $data]));
    };

    return $provider;
}

test('the user can browse and edit a mongodb collection, add a field, and run a mongosh query', function () {
    $log = $this->workspace.'/mongo-requests.jsonl';
    app()->instance(SandboxProvider::class, fakeMongoSandbox($log));
    $requests = fn (string $op) => array_values(array_filter(array_map(fn ($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES)), fn ($request) => $request['op'] === $op));

    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertSeeIn('@db-open-sql', 'Query runner')
        ->click('[data-test="db-table-shop.users"]')
        ->assertSeeIn('@db-row-count', '2 documents')
        ->assertSeeIn('@db-column-age', 'int')
        ->assertSeeIn('@db-cell-1-age', 'missing')

        // Edit a field, and fill in a field none of the shown documents have.
        ->keys('@db-cell-0-age', 'Enter')
        ->type('@db-cell-input', '31')
        ->keys('@db-cell-input', 'Enter')
        ->click('@db-add-field')
        ->type('@db-add-field-name', 'nickname')
        ->keys('@db-add-field-name', 'Enter')
        ->keys('@db-cell-1-nickname', 'Enter')
        ->type('@db-cell-input', 'Bobby')
        ->keys('@db-cell-input', 'Enter')
        ->assertSeeIn('@db-pending', '2 unsaved changes')
        ->click('@db-save')
        ->assertMissing('@db-pending');

    expect($requests('changes')[0])->toMatchArray([
        'connection' => 'docker:workspace-mongo-1',
        'table' => 'shop.users',
        'updates' => [
            ['key' => ['_id' => '65a000000000000000000001'], 'values' => ['age' => '31']],
            ['key' => ['_id' => '65a000000000000000000002'], 'values' => ['nickname' => 'Bobby']],
        ],
    ]);

    // The runner starts on the first collection of its database, and completes collection names after db.
    $page->click('@db-open-sql')
        ->assertSeeIn('@db-sql', 'use shop')
        ->assertSeeIn('@db-sql', 'db.orders.find({}).limit(50)')
        ->clear('@db-sql')
        ->typeSlowly('@db-sql', 'use shop', 10)
        ->keys('@db-sql', 'Enter')
        ->typeSlowly('@db-sql', 'db.us', 20)
        ->assertSeeIn('.cm-tooltip-autocomplete', 'users')
        ->wait(0.2)
        ->keys('@db-sql', 'Enter')
        ->typeSlowly('@db-sql', '.fi', 20)
        ->assertSeeIn('.cm-tooltip-autocomplete', 'findOne')
        ->keys('@db-sql', 'Escape')
        ->typeSlowly('@db-sql', "nd({ name: 'Ann' })", 10)
        ->keys('@db-sql', 'ControlOrMeta+Enter')
        ->assertSeeIn('@db-sql-summary', '1 document')
        ->assertSeeIn('@db-sql-result', 'Ann')
        ->assertNoJavaScriptErrors();

    expect(collect($requests('query'))->last()['sql'])->toStartWith("use shop\ndb.users.find({ name: 'Ann' })");
})->group('DB-002');

test('the user asks ai for a query: a read runs at once, a change waits for run', function () {
    $answers = ["```sql\nselect name from users where id = 2\n```", "```sql\ndelete from users where id = 1\n```"];
    $provider = fakeDatabaseSandbox($this->workspace);
    $database = $provider->execUsing;
    $provider->execUsing = function (array $command, array $env) use ($database, &$answers) {
        return str_contains($command[2] ?? '', 'opencode run')
            ? new ExecResult(0, json_encode(['type' => 'text', 'part' => ['text' => array_shift($answers)]]))
            : $database($command, $env);
    };
    app()->instance(SandboxProvider::class, $provider);

    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertSeeIn('@db-tables', 'users')
        ->click('@db-open-sql')
        ->type('@db-ask-ai-input', 'the second user')
        ->click('@db-ask-ai-submit')
        ->assertSeeIn('@db-sql', 'select name from users where id = 2')
        ->assertSeeIn('@db-sql-summary', '1 row')
        ->assertSeeIn('@db-sql-result', 'Bob')
        ->clear('@db-ask-ai-input')
        ->type('@db-ask-ai-input', 'delete the first user')
        ->click('@db-ask-ai-submit')
        ->assertSeeIn('@db-sql', 'delete from users where id = 1')
        ->assertSeeIn('@db-ask-ai-review', 'hasn’t run');

    expect(array_column(workspaceUsers($this->workspace), 'name'))->toContain('Ann');

    $page->click('@db-run')
        ->assertSeeIn('@db-sql-result', '1 row affected')
        ->assertMissing('@db-ask-ai-review')
        ->assertNoJavaScriptErrors();

    expect(array_column(workspaceUsers($this->workspace), 'name'))->not->toContain('Ann');
})->group('DB-003');

test('the table list can be made wider by its edge, and stays that wide', function () {
    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertAttribute('@db-list-resize', 'aria-valuenow', '224')
        ->keys('@db-list-resize', ['ArrowRight', 'ArrowRight'])
        ->assertAttribute('@db-list-resize', 'aria-valuenow', '256');

    expect($page->script("getComputedStyle(document.querySelector('[data-test=database-panel] aside')).width"))->toBe('256px');

    $page->refresh()
        ->click('@tab-tools')
        ->click('@tool-database')
        ->assertAttribute('@db-list-resize', 'aria-valuenow', '256')
        ->assertNoJavaScriptErrors();
})->group('DB-001');
