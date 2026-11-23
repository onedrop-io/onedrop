<?php

use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\HostedServiceKind;
use App\Enums\OrganizationRole;
use App\Enums\PublishStatus;
use App\Jobs\CheckProjectDomain;
use App\Models\AgentConnection;
use App\Models\HostedService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Hosting\HostingProviders;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config([
        'hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-platform', 'org_slug' => 'onedrop', 'region' => 'iad', 'base_image' => 'ghcr.io/onedrop-io/onedrop-sandbox:latest', 'memory_mb' => 1024, 'volume_gb' => 1],
        'hosting.providers.cloudflare' => ['enabled' => true, 'account_id' => 'acct', 'api_token' => 'cf-token'],
        'hosting.providers.neon' => ['enabled' => true, 'api_key' => 'neon-key', 'region' => 'aws-us-east-1'],
        'hosting.providers.vercel' => ['enabled' => true, 'api_token' => 'vercel-platform', 'team_id' => 'team_onedrop'],
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    Storage::fake('releases', ['serve' => true]);
    Storage::disk('releases')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => []]);
    Storage::disk('releases')->buildTemporaryUrlsUsing(fn (string $path) => "https://bucket.test/{$path}?read");

    app()->instance(Publisher::class, new FakePublisher);
    $this->sandboxes = hostingSandbox();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Bake Sale']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);
});

/**
 * Publish the project to Hosting on a platform.
 */
function publishOn(Project $project, User $user, ?string $platform): void
{
    test()->actingAs($user)
        ->post(route('projects.publication.store', $project), array_filter(['visibility' => 'public', 'target' => 'hosting', 'platform' => $platform]))
        ->assertSessionHasNoErrors();
}

/**
 * A Next.js app with a server that keeps nothing on disk, packed as its source.
 */
function nextApp(object $sandboxes, array $services = []): void
{
    $sandboxes->manifest = ['static' => null, 'services' => $services, 'data' => [], 'sqlite' => [], 'storage' => false, 'vercel' => 'nextjs'];
    $sandboxes->packed = 'source 2048';
    $sandboxes->packedFiles = ['package.json' => '{"dependencies":{"next":"15"}}', 'app/page.tsx' => 'export default () => "hi"'];
}

test('a front end picked for Vercel is served from a Vercel project, single-page routing and all', function () {
    fakeHostingProviders();
    fakeVercel();
    $this->sandboxes->manifest = ['static' => 'dist', 'services' => [], 'data' => [], 'sqlite' => [], 'storage' => false, 'vercel' => null];
    $this->sandboxes->packed = 'static 120';

    publishOn($this->project, $this->user, 'vercel');

    $project = $this->project->fresh();
    $deployment = $project->deployments()->first();
    $web = $project->hostedServices()->where('kind', HostedServiceKind::Web)->first();

    expect($project->hosting_platform)->toBe('vercel')
        ->and($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->published_url)->toBe('https://bake-sale.vercel.app')
        ->and($deployment->host)->toBe('vercel')
        ->and($deployment->kind)->toBe('static')
        ->and($deployment->external_id)->toBe('dpl_1')
        ->and($web->provider)->toBe('vercel')
        ->and($web->external_id)->toBe('prj_1')
        ->and($project->hostedServices()->count())->toBe(1)
        ->and($this->sandboxes->hostingCommands())->toBe(['inspect', 'pack']);

    // A public address, in the install's team, with no framework to build: the files are served as they are.
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.vercel.com/v11/projects?teamId=team_onedrop')
        && array_key_exists('ssoProtection', $request->data()) && $request['ssoProtection'] === null);
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['framework'] === null);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v13/deployments?') && $request['target'] === 'production'
        && collect($request['files'])->pluck('file')->sort()->values()->all() === ['index.html', 'vercel.json']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'workers/scripts') || str_contains($request->url(), 'api.machines.dev'));

    // Vercel's token stays here.
    expect(collect($this->sandboxes->executed)->pluck('env')->flatten()->implode(' '))->not->toContain('vercel-platform');
})->group('HOST-011');

test('a Node app with a server is built by Vercel from its source, with its Postgres on Neon copied from the sandbox once', function () {
    fakeHostingProviders();
    fakeVercel();
    nextApp($this->sandboxes, ['postgres']);

    publishOn($this->project, $this->user, 'vercel');

    $project = $this->project->fresh();

    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->hostedServices()->pluck('kind')->all())->toEqualCanonicalizing([HostedServiceKind::Web, HostedServiceKind::Postgres])
        ->and($this->sandboxes->hostingCommands())->toBe(['inspect', 'copy-postgres', 'pack']);

    $copy = collect($this->sandboxes->executed)->first(fn ($run) => ($run['command'][1] ?? null) === 'copy-postgres');
    expect($copy['env'])->toBe(['ONEDROP_POSTGRES_URL' => 'postgresql://u:p@ep-1.neon.tech/neondb']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['framework'] === 'nextjs');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/env?upsert=true')
        && collect($request->data())->firstWhere('key', 'DATABASE_URL') === ['key' => 'DATABASE_URL', 'value' => 'postgresql://u:p@ep-1.neon.tech/neondb', 'type' => 'sensitive', 'target' => ['production']]
        && collect($request->data())->firstWhere('key', 'ONEDROP_HOSTED')['value'] === '1');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.machines.dev'));

    // The next deploy sends no files Vercel already has, and doesn't copy the data again.
    $uploads = Http::recorded(fn (Request $request) => str_contains($request->url(), '/v2/files'))->count();
    publishOn($this->project, $this->user, 'vercel');

    expect($uploads)->toBe(2)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), '/v2/files'))->count())->toBe(2)
        ->and($this->sandboxes->hostingCommands())->toBe(['inspect', 'copy-postgres', 'pack', 'inspect', 'pack'])
        ->and($this->project->fresh()->deployments()->latest('id')->first()->status)->toBe(DeploymentStatus::Live);
})->group('HOST-011');

test('an app Vercel can\'t run goes to Fly.io instead, and the log says why', function (array $manifest, string $why) {
    fakeHostingProviders();
    fakeVercel();
    $this->sandboxes->manifest = [...['static' => null, 'services' => [], 'data' => [], 'sqlite' => [], 'storage' => false, 'vercel' => 'express'], ...$manifest];

    publishOn($this->project, $this->user, 'vercel');

    $deployment = $this->project->fresh()->deployments()->first();

    expect($deployment->host)->toBe('fly')
        ->and($deployment->status)->toBe(DeploymentStatus::Live)
        ->and($deployment->log)->toContain("Vercel can't run this app: {$why}. Running it on Fly.io instead.");
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.vercel.com'));
})->with([
    'SQLite' => [['data' => ['database/database.sqlite'], 'sqlite' => ['database/database.sqlite']], 'it keeps its data in SQLite (database/database.sqlite)'],
    'data on disk' => [['data' => ['.onedrop/data', 'uploads']], 'it keeps data on disk (uploads)'],
    'not a Node framework' => [['vercel' => null], 'it isn\'t a Node app on a framework Vercel builds (Next.js, Express, Hono…)'],
])->group('HOST-011');

test('a failed Vercel build fails the deploy with Vercel\'s log and leaves the live version alone', function () {
    fakeHostingProviders();
    fakeVercel();
    nextApp($this->sandboxes);
    $GLOBALS['vercelState'] = 'ERROR';

    publishOn($this->project, $this->user, 'vercel');

    $project = $this->project->fresh();
    $deployment = $project->deployments()->first();

    expect($project->publish_status)->toBe(PublishStatus::Failed)
        ->and($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toBe('Vercel couldn\'t build it (Command "npm run build" exited with 1). See the log for details.')
        ->and($deployment->log)->toContain("Vercel's build log:\nRunning \"npm run build\"\nError: Cannot find module \"./db\"");
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/promote/'));
})->group('HOST-011');

test('moving a hosted app from Fly.io to Vercel stops the Fly machine once Vercel has it live, keeping its data', function () {
    fakeHostingProviders();
    fakeVercel();
    $this->sandboxes->manifest = ['static' => null, 'services' => ['postgres'], 'data' => [], 'sqlite' => [], 'storage' => false, 'vercel' => 'express'];
    $this->sandboxes->packed = 'release 2048';
    publishOn($this->project, $this->user, 'fly-cloudflare');
    expect($this->project->fresh()->deployments()->first()->host)->toBe('fly');

    nextApp($this->sandboxes, ['postgres']);
    publishOn($this->project, $this->user, 'vercel');

    $project = $this->project->fresh();
    $latest = $project->deployments()->latest('id')->first();

    expect($latest->host)->toBe('vercel')
        ->and($latest->log)->toContain('Stopped the copy on Fly.io.')
        ->and($project->published_url)->toBe('https://bake-sale.vercel.app')
        ->and($project->hostedServices()->pluck('kind')->all())->toEqualCanonicalizing([HostedServiceKind::App, HostedServiceKind::Postgres, HostedServiceKind::Web])
        ->and($project->hostedServices()->where('kind', HostedServiceKind::App)->first()->details)->not->toHaveKey('machine')
        // The Postgres it already had is kept as it is: not copied from the sandbox again.
        ->and($this->sandboxes->hostingCommands())->not->toContain('copy-postgres');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/machines/machine_app?force=true'));
})->group('HOST-011');

test('an earlier Vercel deploy is put back by promoting it, without building again', function () {
    fakeHostingProviders();
    fakeVercel();
    nextApp($this->sandboxes);
    publishOn($this->project, $this->user, 'vercel');
    $this->sandboxes->packedFiles['app/page.tsx'] = 'export default () => "hello"';
    publishOn($this->project, $this->user, 'vercel');
    $first = $this->project->deployments()->orderBy('id')->first();

    $this->get(route('projects.show', $this->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publication.hosting.host', 'vercel')
            ->where('publication.hosting.machine', false)
            ->has('publication.hosting.history', 2)
            ->where('publication.hosting.history.1.number', 1));

    $this->post(route('projects.hosting.roll-back', [$this->project, $first]))->assertSessionHasNoErrors();

    $latest = $this->project->deployments()->latest('id')->first();
    expect($latest->status)->toBe(DeploymentStatus::Live)
        ->and($latest->external_id)->toBe('dpl_1')
        ->and($latest->log)->toContain('Putting deploy #1 back (dpl_1).')
        ->and($this->project->fresh()->publish_status)->toBe(PublishStatus::Live);
    Http::assertSent(fn (Request $request) => str_ends_with(strtok($request->url(), '?'), '/v10/projects/prj_1/promote/dpl_1'));
})->group('HOST-011', 'HOST-005');

test('unpublishing deletes the Vercel project and keeps the app\'s data', function () {
    fakeHostingProviders();
    fakeVercel();
    nextApp($this->sandboxes, ['postgres']);
    publishOn($this->project, $this->user, 'vercel');

    $this->delete(route('projects.publication.destroy', $this->project))->assertSessionHasNoErrors();

    expect($this->project->hostedServices()->pluck('kind')->all())->toBe([HostedServiceKind::Postgres]);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_starts_with($request->url(), 'https://api.vercel.com/v9/projects/prj_1'));
})->group('HOST-011');

test('the Publish panel offers Vercel beside Fly.io and Cloudflare, and keeps the project\'s choice', function () {
    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publication.platforms.0.value', 'fly-cloudflare')
            ->where('publication.platforms.1.value', 'vercel')
            ->where('publication.platform', 'fly-cloudflare'));

    $this->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'hosting', 'platform' => 'heroku'])
        ->assertSessionHasErrors('platform');

    Queue::fake();
    publishOn($this->project, $this->user, 'vercel');

    $this->get(route('projects.show', $this->project))->assertInertia(fn (AssertableInertia $page) => $page->where('publication.platform', 'vercel'));
})->group('HOST-011');

test('an organization with only its own Vercel account hosts on Vercel by default, with no release storage needed', function () {
    config([
        'hosting.providers.fly.enabled' => false,
        'hosting.providers.cloudflare.enabled' => false,
        'hosting.providers.vercel.enabled' => false,
        'filesystems.disks.releases' => ['driver' => 'local'],
    ]);
    $organization = Organization::factory()->create();
    $organization->addMember($this->user, OrganizationRole::Owner);
    $this->project->update(['organization_id' => $organization->id]);
    app(HostingProviders::class)->connect($organization, 'vercel', ['api_token' => 'vercel-theirs']);

    expect(app(HostingProviders::class)->defaultPlatform($organization->fresh()))->toBe('vercel');

    fakeVercel();
    $this->sandboxes->manifest = ['static' => 'dist', 'services' => [], 'data' => [], 'sqlite' => [], 'storage' => false, 'vercel' => null];
    $this->sandboxes->packed = 'static 120';
    publishOn($this->project->fresh(), $this->user, null);

    $project = $this->project->fresh();
    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->hostedServices()->first()->owner)->toBe(HostedService::OWNER_ORGANIZATION);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.vercel.com') && $request->header('Authorization')[0] === 'Bearer vercel-theirs');
})->group('HOST-011', 'HOST-003');

test('a custom domain on a Vercel app is added to its project, with the records Vercel recommends', function () {
    Queue::fake([CheckProjectDomain::class]);
    fakeHostingProviders();
    fakeVercel();
    nextApp($this->sandboxes);
    publishOn($this->project, $this->user, 'vercel');
    $GLOBALS['vercelMisconfigured'] = true;

    $this->postJson(route('projects.domains.store', $this->project), ['hostname' => 'app.example.com'])
        ->assertOk()
        ->assertJsonPath('domains.0.records', [['type' => 'CNAME', 'name' => 'app.example.com', 'value' => 'd1d4fc829fe7bc7c.vercel-dns-017.com']]);
    $this->postJson(route('projects.domains.store', $this->project), ['hostname' => 'example.com'])
        ->assertJsonPath('domains.1.records', [['type' => 'A', 'name' => 'example.com', 'value' => '76.76.21.21']]);

    $domain = $this->project->domains()->where('hostname', 'app.example.com')->first();
    expect($domain->via)->toBe('vercel');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v10/projects/prj_1/domains') && $request['name'] === 'app.example.com');

    $this->postJson(route('projects.domains.check', [$this->project, $domain]))->assertJsonPath('domains.0.error', 'Waiting for app.example.com\'s DNS.');

    $GLOBALS['vercelMisconfigured'] = false;
    $this->postJson(route('projects.domains.check', [$this->project, $domain]))->assertJsonPath('domains.0.status', DomainStatus::Active->value);
    expect($this->project->fresh()->published_url)->toBe('https://app.example.com');
    unset($GLOBALS['vercelMisconfigured']);
})->group('HOST-011', 'DOM-001');
