<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->dev)->create();

    config([
        'hosting.providers.fly' => ['enabled' => false, 'api_token' => null, 'org_slug' => 'personal', 'region' => 'iad', 'base_image' => 'ghcr.io/onedrop-io/onedrop-sandbox:latest', 'memory_mb' => 1024, 'volume_gb' => 1],
        'hosting.providers.cloudflare.enabled' => false,
        'hosting.providers.neon.enabled' => false,
        'hosting.providers.upstash.enabled' => false,
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    Storage::fake('releases', ['serve' => true]);
    Storage::disk('releases')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => []]);
    Storage::disk('releases')->buildTemporaryUrlsUsing(fn (string $path) => "https://bucket.test/{$path}?read");
    app()->instance(Publisher::class, new FakePublisher);
    hostingSandbox();
    fakeHostingProviders();
});

test('an admin sets up Fly.io, then publishes an app to hosting and sees it live with its deploy log', function () {
    $project = Project::factory()->for($this->dev)->create(['name' => 'Bake Sale']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'external_id' => 'sbx-1']);

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("Hosting")')
        ->assertPathIs('/admin/hosting')
        ->click('@hosting-fly-select')
        ->assertSeeIn('@hosting-fly', 'Apps with a server')
        ->fill('#fly-api_token', 'fly-token')
        ->press('@hosting-fly-save')
        ->assertSee('Fly.io saved.')
        ->click('@hosting-fly-enabled')
        ->assertSeeIn('@hosting-fly-item', 'in its account');

    // Saving settings makes the sandbox provider again (SystemConfig), so the fake one is put back.
    hostingSandbox();

    $page->navigate("/projects/{$project->id}")
        ->click('@publish-button')
        ->click('[data-test="publish-panel"] label:has-text("Hosting")')
        ->assertSeeIn('@publish-panel', 'Runs on its own, off the sandbox')
        ->assertMissing('@visibility-private')
        ->click('@publish-submit')
        ->assertSeeIn('@published-url', '.fly.dev')
        ->click('[data-test="publish-panel"] summary')
        ->assertSeeIn('@deploy-log', 'Live at https://')
        // The address in the log opens the app, without the sentence's full stop.
        ->assertPresent('[data-test="deploy-log"] a[target="_blank"][href$=".fly.dev"]')
        // The rest of hosting is in Tools → Publishing, so the menu stays short.
        ->click('@manage-hosting')
        ->assertVisible('@tool-page-publishing')
        ->assertSeeIn('@hosted-services', 'Data volume')
        ->assertSelected('@machine-size', 'small')
        ->assertNoJavaScriptErrors();

    expect($project->fresh()->published_url)->toEndWith('.fly.dev');
})->group('HOST-001', 'ADMIN-007');

test('after publishing, the panel shows what changed since and updates the hosted app', function () {
    config(['hosting.providers.fly.enabled' => true, 'hosting.providers.fly.api_token' => 'fly-token']);
    $project = Project::factory()->for($this->dev)->create(['name' => 'Bake Sale']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'external_id' => 'sbx-1']);
    $this->actingAs($this->dev);
    $this->post(route('projects.publication.store', $project), ['visibility' => 'public', 'target' => 'hosting']);
    $project->update(['hosting_changes' => ['count' => 2, 'commits' => [['sha' => 'abc1234', 'message' => 'Add a dark mode'], ['sha' => 'def5678', 'message' => 'Fix the dose reminder']]]]);

    visit("/projects/{$project->id}")
        ->assertPresent('@publish-pending')
        ->click('@publish-button')
        ->assertSeeIn('@hosting-changes', '2 changes since you published')
        ->assertSeeIn('@hosting-changes', 'Add a dark mode')
        ->assertSeeIn('@publish-submit', 'Update')
        ->click('@publish-submit')
        ->assertSeeIn('@publish-status', 'published')
        ->assertMissing('@hosting-changes')
        ->assertNoJavaScriptErrors();

    expect($project->deployments()->count())->toBe(2);
})->group('HOST-004');

test('a hosted SQLite app offers Move to Postgres, and says when a move is waiting for the next update', function () {
    config(['hosting.providers.fly.enabled' => true, 'hosting.providers.fly.api_token' => 'fly-token']);
    $sandboxes = hostingSandbox();
    $sandboxes->manifest = ['static' => null, 'services' => [], 'data' => ['database/database.sqlite'], 'sqlite' => ['database/database.sqlite'], 'storage' => false];
    $project = Project::factory()->for($this->dev)->create(['name' => 'Dose Tracker']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'external_id' => 'sbx-1']);
    $this->actingAs($this->dev);
    $this->post(route('projects.publication.store', $project), ['visibility' => 'public', 'target' => 'hosting']);

    visit("/projects/{$project->id}?tab=tools&tool=publishing")
        ->assertSeeIn('@hosting-details', 'SQLite (database/database.sqlite)')
        ->assertVisible('@move-to-postgres')
        ->assertNoJavaScriptErrors();

    $project->update(['hosting_sqlite_import' => 'database/database.sqlite']);

    visit("/projects/{$project->id}?tab=tools&tool=publishing")
        ->assertSeeIn('@moving-to-postgres', 'Moving to Postgres')
        ->assertMissing('@move-to-postgres')
        ->assertNoJavaScriptErrors();
})->group('HOST-009');

test('on a narrow window, Manage hosting switches from the chat to the workspace', function () {
    config(['hosting.providers.fly.enabled' => true, 'hosting.providers.fly.api_token' => 'fly-token']);
    hostingSandbox();
    $project = Project::factory()->for($this->dev)->create(['name' => 'Bake Sale']);
    Sandbox::factory()->for($project)->create(['preview_url' => null, 'external_id' => 'sbx-1']);
    $this->actingAs($this->dev);
    $this->post(route('projects.publication.store', $project), ['visibility' => 'public', 'target' => 'hosting']);

    visit("/projects/{$project->id}")
        ->resize(900, 900)
        ->assertVisible('section[aria-label="Chat"]')
        ->click('@publish-button')
        ->click('@manage-hosting')
        ->assertMissing('section[aria-label="Chat"]')
        ->assertVisible('@tool-page-publishing')
        ->assertVisible('@hosted-services')
        ->assertNoJavaScriptErrors();
})->group('HOST-001');
