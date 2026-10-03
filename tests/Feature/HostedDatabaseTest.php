<?php

use App\Enums\HostedServiceKind;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\DeleteDatabaseCopy;
use App\Models\AgentConnection;
use App\Models\HostedService;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Tools → Database against a hosted app (HOST-007): docker/sandbox/db.php runs for real against a local workspace,
 * behind a faked Fly exec endpoint that hands it the command's environment.
 */

beforeEach(function () {
    config([
        'hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-platform', 'org_slug' => 'onedrop', 'region' => 'iad', 'base_image' => 'x', 'memory_mb' => 1024, 'volume_gb' => 1],
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    Storage::fake('releases', ['serve' => true]);
    Storage::disk('releases')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => ['Content-Type' => 'application/octet-stream']]);
    Storage::disk('releases')->buildTemporaryUrlsUsing(fn (string $path, $expiration, array $options) => "https://bucket.test/{$path}?read&".http_build_query($options));
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);

    $this->workspace = databaseWorkspace();
    $this->machineState = 'suspended';
    $this->execs = [];

    Http::fake([
        'api.machines.dev/v1/apps/*/machines/m_app/start' => Http::response([]),
        'api.machines.dev/v1/apps/*/machines/m_app/wait*' => Http::response(['ok' => true, 'state' => 'started']),
        'api.machines.dev/v1/apps/*/machines/m_app/exec' => function (Request $request) {
            $this->execs[] = $request->data();

            return Http::response(runHostedDatabaseCommand($this->workspace, $request['command']));
        },
        'api.machines.dev/v1/apps/*/machines/m_app' => fn () => Http::response(['state' => $this->machineState, 'config' => []]),
    ]);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'name' => 'Dose Tracker',
        'publish_target' => PublishTarget::Hosting,
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Public,
        'published_url' => 'https://dose-tracker.fly.dev',
    ]);
    // The sandbox is asleep: the hosted database doesn't need it.
    Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1', 'status' => SandboxStatus::Paused]);
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::App, 'name' => 'dose-tracker-1-abcd', 'details' => ['machine' => 'm_app']]);
});

test("the hosted app's database is browsed on its machine, woken first, as the sandbox user", function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.database.connections', [$this->project, 'where' => 'hosted']))
        ->assertOk()
        ->assertJsonPath('connections.0.id', 'sqlite:database/database.sqlite');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/machines/m_app/start'));

    $exec = $this->execs[0];
    expect($exec['command'][0])->toBe('/bin/sh')
        ->and($exec['command'][2])->toContain('runuser -u sandbox')
        ->and($exec['command'])->toContain('ONEDROP_HOSTED=1')
        ->and($exec['command'])->toContain('APP_DB_REQUEST={"op":"connections"}')
        ->and(end($exec['command']))->toContain('/opt/onedrop-hosting/db.php');

    $this->actingAs($this->user)
        ->getJson(route('projects.database.rows', [$this->project, 'where' => 'hosted', 'connection' => 'sqlite:database/database.sqlite', 'table' => 'users']))
        ->assertOk()
        ->assertJsonPath('total', 3);
})->group('HOST-007');

test('edits and queries on the hosted database reach it', function () {
    $this->machineState = 'started';

    $this->actingAs($this->user)
        ->postJson(route('projects.database.query', $this->project), ['where' => 'hosted', 'connection' => 'sqlite:database/database.sqlite', 'sql' => "update users set name = 'Ann B' where id = 1"])
        ->assertOk();

    expect((new PDO('sqlite:'.$this->workspace.'/database/database.sqlite'))->query('select name from users where id = 1')->fetchColumn())->toBe('Ann B');
    // Already awake: not started again.
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/start'));
})->group('HOST-007');

test('the hosted app gets the database it was given first, and not the servers in its sandbox .env', function () {
    $this->machineState = 'started';
    file_put_contents($this->workspace.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_DATABASE=app\nDB_USERNAME=app\n");
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::Postgres, 'provider' => 'neon', 'name' => 'dose-db', 'details' => ['url' => 'postgresql://u:secret@ep-1.neon.tech/neondb']]);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.database.connections', [$this->project, 'where' => 'hosted']))
        ->assertOk();

    expect($response->json('connections.0'))->toMatchArray(['id' => 'env:hosted:DATABASE_URL', 'driver' => 'pgsql', 'source' => 'Hosting (DATABASE_URL)'])
        ->and(collect($response->json('connections'))->pluck('id'))->not->toContain('env:.env:DB_CONNECTION')
        ->and($response->getContent())->not->toContain('secret');
})->group('HOST-007');

test('a project not published to hosting has no hosted database', function () {
    $this->project->update(['publish_status' => null]);

    $this->actingAs($this->user)
        ->getJson(route('projects.database.connections', [$this->project, 'where' => 'hosted']))
        ->assertConflict();
})->group('HOST-007');

test('a SQLite database downloads as a consistent copy through a short-lived link, deleted later', function (string $where) {
    Queue::fake([DeleteDatabaseCopy::class]);
    $this->machineState = 'started';
    $sandboxes = new FakeSandboxProvider;
    $sandboxes->execUsing = fn (array $command, array $env) => new ExecResult(0, runDatabaseScript($this->workspace, $env['APP_DB_REQUEST']));
    app()->instance(SandboxProvider::class, $sandboxes);
    $this->project->sandbox->update(['status' => SandboxStatus::Running]);
    // The copy goes to the signed link with curl: point a stand-in curl at a folder.
    $bin = sys_get_temp_dir().'/onedrop-curl-'.bin2hex(random_bytes(3));
    mkdir($bin);
    file_put_contents("{$bin}/curl", "#!/bin/sh\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = -T ] && cp \"\$2\" {$bin}/uploaded.sqlite; shift; done\n");
    chmod("{$bin}/curl", 0755);
    putenv('PATH='.$bin.':'.getenv('PATH'));

    try {
        $response = $this->actingAs($this->user)
            ->postJson(route('projects.database.download', [$this->project, 'where' => $where]), ['connection' => 'sqlite:database/database.sqlite'])
            ->assertOk();
    } finally {
        putenv('PATH='.str_replace($bin.':', '', getenv('PATH')));
    }

    expect($response->json('url'))->toContain('database-copies/'.$this->project->id.'/')
        ->and(urldecode($response->json('url')))->toContain('attachment; filename="dose-tracker'.($where === 'hosted' ? '-hosted' : ''))
        ->and($response->json('bytes'))->toBeGreaterThan(0)
        ->and((new PDO("sqlite:{$bin}/uploaded.sqlite"))->query('select count(*) from users')->fetchColumn())->toBe(3);

    Queue::assertPushed(DeleteDatabaseCopy::class);
    exec('rm -rf '.escapeshellarg($bin));
})->with(['sandbox', 'hosted'])->group('HOST-007');
