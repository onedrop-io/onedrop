<?php

use App\Enums\HostedServiceKind;
use App\Enums\PublishStatus;
use App\Http\Controllers\ProjectHostingController;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Move to Postgres (HOST-009): a hosted SQLite app is switched over by the agent, and the next deploy makes a Neon
 * database and has the machine copy the hosted SQLite data into it, once. The copy itself is tested against a real
 * Postgres in tests/Integration/PostgresImportDockerTest.php.
 */

beforeEach(function () {
    config([
        'hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-platform', 'org_slug' => 'onedrop', 'region' => 'iad', 'base_image' => 'x', 'memory_mb' => 1024, 'volume_gb' => 1],
        'hosting.providers.neon' => ['enabled' => true, 'api_key' => 'neon-key', 'region' => 'aws-us-east-1'],
        'hosting.providers.cloudflare.enabled' => false,
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    Storage::fake('releases', ['serve' => true]);
    Storage::disk('releases')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => []]);
    Storage::disk('releases')->buildTemporaryUrlsUsing(fn (string $path) => "https://bucket.test/{$path}?read");
    app()->instance(Publisher::class, new FakePublisher);
    $this->sandboxes = hostingSandbox();
    $this->sandboxes->manifest = ['static' => null, 'services' => [], 'data' => ['database/database.sqlite'], 'sqlite' => ['database/database.sqlite'], 'storage' => false];
    $this->sandboxes->packed = "release 2048\nseed 512\npostgres 100";
    fakeHostingProviders();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Dose Tracker']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);

    $this->publish = fn () => $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'hosting'])
        ->assertSessionHasNoErrors();
    ($this->publish)();
});

afterEach(function () {
    unset($GLOBALS['hostedAppStatus'], $GLOBALS['hostedAppBody'], $GLOBALS['hostedAppHeaders']);
});

test('a hosted SQLite app offers Move to Postgres, which asks the agent to switch it over', function () {
    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('publication.hosting.sqlite', 'database/database.sqlite')
            ->where('publication.hosting.moving_to_postgres', false));

    $this->actingAs($this->user)->post(route('projects.hosting.move-to-postgres', $this->project))->assertSessionHasNoErrors();

    expect($this->project->fresh()->hosting_sqlite_import)->toBe('database/database.sqlite')
        ->and($this->project->allMessages()->where('content', ProjectHostingController::MOVE_TO_POSTGRES_REQUEST)->exists())->toBeTrue();
})->group('HOST-009');

test('the next deploy makes Postgres and has the machine copy the hosted SQLite data in, not the sandbox Postgres', function () {
    $this->project->update(['hosting_sqlite_import' => 'database/database.sqlite']);
    // The agent switched the app over.
    $this->sandboxes->manifest['services'] = ['postgres'];

    ($this->publish)();

    $env = appMachineRequest()['config']['env'];
    $builder = Http::recorded(fn (Request $request) => ($request['config']['metadata']['onedrop'] ?? null) === 'builder')->last()[0];
    $project = $this->project->fresh();

    expect($project->hostedServices()->where('kind', HostedServiceKind::Postgres)->exists())->toBeTrue()
        ->and($env['ONEDROP_IMPORT_SQLITE'])->toBe('database/database.sqlite')
        ->and($env['DB_CONNECTION'])->toBe('pgsql')
        ->and($env['ONEDROP_VOLUME'])->toBe('1')
        ->and($builder['config']['env'])->not->toHaveKey('ONEDROP_POSTGRES_DUMP_URL')
        ->and($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->hosting_sqlite_import)->toBeNull()
        ->and($project->deployments()->latest('id')->first()->log)->toContain('Moved its SQLite data into Postgres');

    // Done: the next deploy doesn't copy again.
    ($this->publish)();
    expect(appMachineRequest()['config']['env'])->not->toHaveKey('ONEDROP_IMPORT_SQLITE');
})->group('HOST-009');

test('until the agent has switched the app over, deploys carry on with SQLite and the move waits', function () {
    $this->project->update(['hosting_sqlite_import' => 'database/database.sqlite']);

    ($this->publish)();

    expect(appMachineRequest()['config']['env'])->not->toHaveKey('ONEDROP_IMPORT_SQLITE')
        ->and($this->project->fresh()->hosting_sqlite_import)->toBe('database/database.sqlite');

    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('publication.hosting.moving_to_postgres', true));
})->group('HOST-009');

test('a move that fails puts the SQLite version back, without Postgres in its environment', function () {
    $this->project->update(['hosting_sqlite_import' => 'database/database.sqlite']);
    $this->sandboxes->manifest['services'] = ['postgres'];
    $GLOBALS['hostedAppStatus'] = 503;
    $GLOBALS['hostedAppHeaders'] = ['X-OneDrop-App' => 'crashed'];
    $GLOBALS['hostedAppBody'] = "The app's Postgres tables don't match its SQLite ones (table notes isn't in Postgres).";

    ($this->publish)();

    $project = $this->project->fresh();
    $restored = appMachineRequest()['config'];

    expect($project->publish_status)->toBe(PublishStatus::Failed)
        ->and($project->deployments()->latest('id')->first()->log)->toContain("table notes isn't in Postgres")
        ->and($restored['image'])->toBe($project->deployments()->first()->image)
        ->and($restored['env'])->not->toHaveKeys(['DATABASE_URL', 'DB_CONNECTION', 'DB_URL', 'ONEDROP_IMPORT_SQLITE'])
        // Still to do: the next update tries again.
        ->and($project->hosting_sqlite_import)->toBe('database/database.sqlite');
})->group('HOST-009');

test("an app that doesn't keep its data in SQLite can't move", function () {
    $other = Project::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->post(route('projects.hosting.move-to-postgres', $other))
        ->assertSessionHasErrors(['publish' => 'Only a hosted app that keeps its data in SQLite can move to Postgres.']);
})->group('HOST-009');
