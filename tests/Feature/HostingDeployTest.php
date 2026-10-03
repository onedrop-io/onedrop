<?php

use App\Actions\DeleteProject;
use App\Console\Commands\SuspendIdleSandboxes;
use App\Enums\DeploymentStatus;
use App\Enums\HostedServiceKind;
use App\Enums\OrganizationRole;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\Deployment;
use App\Models\HostedService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SystemSetting;
use App\Models\User;
use App\Sandbox\Hosting\Deployer;
use App\Sandbox\Hosting\HostingProviders;
use App\Sandbox\Hosting\ReleaseStorage;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config([
        'hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-platform', 'org_slug' => 'onedrop', 'region' => 'iad', 'base_image' => 'ghcr.io/onedrop-io/onedrop-sandbox:latest', 'memory_mb' => 1024, 'volume_gb' => 1],
        'hosting.providers.neon' => ['enabled' => true, 'api_key' => 'neon-key', 'region' => 'aws-us-east-1'],
        'hosting.providers.upstash' => ['enabled' => true, 'email' => 'ops@example.com', 'api_key' => 'up-key', 'region' => 'us-east-1'],
        'hosting.providers.cloudflare' => ['enabled' => true, 'account_id' => 'acct', 'api_token' => 'cf-token'],
        'sandbox.snapshot_disk' => 'releases',
        'filesystems.disks.releases' => ['driver' => 's3'],
    ]);
    Storage::fake('releases', ['serve' => true]);
    Storage::disk('releases')->buildTemporaryUploadUrlsUsing(fn (string $path) => ['url' => "https://bucket.test/{$path}?signed", 'headers' => ['Content-Type' => 'application/gzip']]);
    Storage::disk('releases')->buildTemporaryUrlsUsing(fn (string $path) => "https://bucket.test/{$path}?read");

    app()->instance(Publisher::class, new FakePublisher);
    $this->sandboxes = hostingSandbox();

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff']);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Bake Sale']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);
});

/**
 * Publish the project to Hosting as the user.
 */
function publishToHosting(Project $project, User $user, string $visibility = 'public'): void
{
    test()->actingAs($user)
        ->post(route('projects.publication.store', $project), ['visibility' => $visibility, 'target' => 'hosting'])
        ->assertSessionHasNoErrors();
}

test('publishing an app with a server to hosting runs it on a Fly machine with its data on a volume', function () {
    fakeHostingProviders();

    publishToHosting($this->project, $this->user);

    $project = $this->project->fresh();
    $app = $project->hostedServices()->where('kind', HostedServiceKind::App)->first();
    $deployment = $project->deployments()->first();

    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->publish_target)->toBe(PublishTarget::Hosting)
        ->and($project->published_url)->toBe("https://{$app->name}.fly.dev")
        ->and($app->name)->toStartWith("bake-sale-{$project->id}-")
        ->and($app->owner)->toBe(HostedService::OWNER_PLATFORM)
        ->and($project->hostedServices()->pluck('kind')->all())->toEqualCanonicalizing([HostedServiceKind::App, HostedServiceKind::Volume])
        ->and($deployment->status)->toBe(DeploymentStatus::Live)
        ->and($deployment->kind)->toBe('server')
        ->and($deployment->image)->toBe("registry.fly.io/{$app->name}:deployment-1")
        ->and($this->sandboxes->hostingCommands())->toBe(['inspect', 'pack seed', 'put', 'put']);

    // The sandbox uploads through signed links only; the provider's token goes to the builder in Fly.
    expect(collect($this->sandboxes->executed)->pluck('env')->flatten()->implode(' '))->not->toContain('fly-platform');

    $builder = Http::recorded(fn (Request $request) => ($request['config']['metadata']['onedrop'] ?? null) === 'builder')->first()[0];
    expect($builder['config']['image'])->toBe('ghcr.io/onedrop-io/onedrop-sandbox:latest')
        ->and($builder['config']['env']['FLY_API_TOKEN'])->toBe('fly-platform')
        ->and($builder['config']['env']['ONEDROP_IMAGE'])->toBe($deployment->image)
        ->and($builder['config']['env']['ONEDROP_RELEASE_URL'])->toContain("hosting/{$project->id}/1/release.tar.gz")
        ->and($builder['config']['env'])->not->toHaveKey('ONEDROP_POSTGRES_DUMP_URL');

    $machine = appMachineRequest();
    expect($machine['config']['image'])->toBe($deployment->image)
        ->and($machine['config']['mounts'])->toBe([['volume' => 'vol_123', 'path' => '/data']])
        ->and($machine['config']['env']['ONEDROP_VOLUME'])->toBe('1')
        ->and($machine['config']['env']['ONEDROP_DATA'])->toBe('.onedrop/data')
        ->and($machine['config']['env']['ONEDROP_SEED_URL'])->toContain("hosting/{$project->id}/1/seed.tar.gz")
        ->and($machine['config']['services'][0]['autostop'])->toBe('suspend')
        ->and($machine['config']['services'][0]['autostart'])->toBeTrue()
        ->and($machine['config']['services'][0]['min_machines_running'])->toBe(0);

    // The release is deleted once the image has it; the seed waits for the first boot.
    Storage::disk('releases')->assertMissing("hosting/{$project->id}/1/release.tar.gz");
})->group('HOST-001', 'HOST-002');

test('postgres and redis go to Neon and Upstash, and the sandbox postgres is copied once', function () {
    fakeHostingProviders();
    $this->sandboxes->manifest = ['static' => null, 'services' => ['postgres', 'redis'], 'data' => [], 'storage' => false];
    $this->sandboxes->packed = "release 2048\npostgres 512";

    publishToHosting($this->project, $this->user);

    $project = $this->project->fresh();
    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->hostedServices()->pluck('kind')->all())->toEqualCanonicalizing([HostedServiceKind::App, HostedServiceKind::Postgres, HostedServiceKind::Redis]);

    $builder = Http::recorded(fn (Request $request) => ($request['config']['metadata']['onedrop'] ?? null) === 'builder')->first()[0];
    expect($builder['config']['env']['ONEDROP_POSTGRES_URL'])->toBe('postgresql://u:p@ep-1.neon.tech/neondb')
        ->and($builder['config']['env']['ONEDROP_POSTGRES_DUMP_URL'])->toContain('postgres.dump');

    $env = appMachineRequest()['config']['env'];
    expect($env['DATABASE_URL'])->toBe('postgresql://u:p@ep-1.neon.tech/neondb')
        ->and($env['DB_CONNECTION'])->toBe('pgsql')
        ->and($env['REDIS_URL'])->toBe('rediss://default:secret@calm-fox-123.upstash.io:6379')
        ->and($env)->not->toHaveKey('ONEDROP_VOLUME')
        ->and(appMachineRequest()['config']['mounts'])->toBe([]);

    // Neon's database sleeps when idle too.
    Http::assertSent(fn (Request $request) => $request->url() === 'https://console.neon.tech/api/v2/projects'
        && ! isset($request['project']['default_endpoint_settings']));
})->group('HOST-002');

test('an app using S3 gets an R2 bucket with a key for that bucket only', function () {
    fakeHostingProviders();
    $this->sandboxes->manifest = ['static' => null, 'services' => ['s3'], 'data' => [], 'storage' => false];
    $this->sandboxes->packed = 'release 2048';

    publishToHosting($this->project, $this->user);

    $bucket = $this->project->hostedServices()->where('kind', HostedServiceKind::Bucket)->first();
    $env = appMachineRequest()['config']['env'];

    expect($env['AWS_BUCKET'])->toBe($bucket->name)
        ->and($env['AWS_ACCESS_KEY_ID'])->toBe('tok_1')
        ->and($env['AWS_SECRET_ACCESS_KEY'])->toBe(hash('sha256', 'token-value'))
        ->and($env['AWS_ENDPOINT'])->toBe('https://acct.r2.cloudflarestorage.com');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accounts/acct/tokens')
        && array_keys($request['policies'][0]['resources']) === ["com.cloudflare.edge.r2.bucket.acct_default_{$bucket->name}"]);
})->group('HOST-002');

test('deploying again ships code only, keeps the data and updates the same machine', function () {
    fakeHostingProviders();
    publishToHosting($this->project, $this->user);
    $volumes = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/volumes'))->count();

    publishToHosting($this->project, $this->user);

    $project = $this->project->fresh();
    $latest = $project->deployments()->latest('id')->first();

    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($latest->number)->toBe(2)
        ->and($this->sandboxes->hostingCommands())->toBe(['inspect', 'pack seed', 'put', 'put', 'inspect', 'pack', 'put'])
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/volumes'))->count())->toBe($volumes)
        ->and(appMachineRequest()['config']['env'])->not->toHaveKey('ONEDROP_SEED_URL');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/machines/machine_app')
        && $request['config']['image'] === $latest->image);
})->group('HOST-001');

test('a front end is served from Cloudflare without a Fly machine', function () {
    fakeHostingProviders();
    $this->sandboxes->manifest = ['static' => 'dist', 'services' => [], 'data' => [], 'storage' => false];
    $this->sandboxes->packed = 'static 120';

    // What the sandbox would have uploaded: a site with its index.html.
    $site = sys_get_temp_dir().'/site-'.uniqid();
    mkdir($site);
    file_put_contents("{$site}/index.html", '<h1>Bake sale</h1>');
    Process::run(['tar', '-czf', "{$site}.tar.gz", '-C', $site, '.']);
    Storage::disk('releases')->put("hosting/{$this->project->id}/1/static.tar.gz", file_get_contents("{$site}.tar.gz"));

    publishToHosting($this->project, $this->user);

    $project = $this->project->fresh();
    $worker = $project->hostedServices()->where('kind', HostedServiceKind::Site)->first();

    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->published_url)->toBe("https://{$worker->name}.acme.workers.dev")
        ->and($project->hostedServices()->count())->toBe(1);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/assets-upload-session')
        && array_keys($request['manifest']) === ['/index.html']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.machines.dev'));
})->group('HOST-001');

test('a failed build leaves the running version alone and says why', function () {
    fakeHostingProviders(['builder_exit' => 1]);

    publishToHosting($this->project, $this->user);

    $project = $this->project->fresh();

    expect($project->publish_status)->toBe(PublishStatus::Failed)
        ->and($project->publish_error)->toContain('Building the image failed (exit 1)')
        ->and($project->deployments()->first()->status)->toBe(DeploymentStatus::Failed)
        ->and(appMachineRequest())->toBeNull();

    // The builder machine is cleaned up.
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/machines/builder_1'));
})->group('HOST-001');

test('a failed build in the sandbox is reported with its output', function () {
    fakeHostingProviders();
    $this->sandboxes->packError = ".onedrop/build failed:\nnpm ERR! missing script: build";

    publishToHosting($this->project, $this->user);

    expect($this->project->fresh()->publish_error)->toContain('npm ERR! missing script: build')
        ->and($this->project->hostedServices()->count())->toBe(0);
})->group('HOST-001');

test('a release that never answers puts the last good version back', function () {
    fakeHostingProviders();
    publishToHosting($this->project, $this->user);
    $good = $this->project->deployments()->first()->image;

    $GLOBALS['hostedAppStatus'] = 502;
    publishToHosting($this->project, $this->user);
    unset($GLOBALS['hostedAppStatus']);

    $project = $this->project->fresh();
    expect($project->publish_status)->toBe(PublishStatus::Failed)
        ->and($project->publish_error)->toContain("didn't start")
        ->and(appMachineRequest()['config']['image'])->toBe($good);
})->group('HOST-001');

test('a release that keeps stopping as it starts fails at once with its output, and puts the last good version back', function () {
    fakeHostingProviders();
    publishToHosting($this->project, $this->user);
    $good = $this->project->deployments()->first()->image;

    $GLOBALS['hostedAppStatus'] = 503;
    $GLOBALS['hostedAppHeaders'] = ['X-OneDrop-App' => 'crashed'];
    $GLOBALS['hostedAppBody'] = 'Error: Cannot find module @rolldown/binding-linux-x64-gnu';
    publishToHosting($this->project, $this->user);
    unset($GLOBALS['hostedAppStatus'], $GLOBALS['hostedAppHeaders'], $GLOBALS['hostedAppBody']);

    $project = $this->project->fresh();
    $deployment = $project->deployments()->latest('id')->first();
    expect($project->publish_status)->toBe(PublishStatus::Failed)
        ->and($project->publish_error)->toContain('keeps stopping')
        ->and($deployment->polls)->toBe(0)
        ->and($deployment->log)->toContain('Cannot find module @rolldown/binding-linux-x64-gnu')
        ->and(appMachineRequest()['config']['image'])->toBe($good);
})->group('HOST-001');

test('a Vite app with no host.json is served as a front end, and the log says why', function () {
    fakeHostingProviders();
    $this->sandboxes->manifest = ['static' => 'dist', 'services' => [], 'data' => [], 'storage' => false, 'guessed' => true];
    $this->sandboxes->packed = 'static 120';
    $site = sys_get_temp_dir().'/site-'.uniqid();
    mkdir($site);
    file_put_contents("{$site}/index.html", '<h1>Counter</h1>');
    Process::run(['tar', '-czf', "{$site}.tar.gz", '-C', $site, '.']);
    Storage::disk('releases')->put("hosting/{$this->project->id}/1/static.tar.gz", file_get_contents("{$site}.tar.gz"));

    publishToHosting($this->project, $this->user);

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and($this->project->deployments()->first()->log)->toContain('A Vite app with no .onedrop/host.json, so a front end: serving dist/');
})->group('HOST-001');

test('the builder installs the packages again when the sandbox that packed them has another CPU', function () {
    expect(Deployer::builderScript())
        ->toContain('opt/onedrop-hosting/arch')
        ->toContain('run deps');
})->group('HOST-001');

test('a release whose machine already went to sleep still goes live, since asking wakes it', function () {
    fakeHostingProviders();
    $GLOBALS['hostedMachineState'] = 'suspended';

    publishToHosting($this->project, $this->user);
    unset($GLOBALS['hostedMachineState']);

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Live);
})->group('HOST-001');

test('hosted apps are public only', function () {
    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'private', 'target' => 'hosting'])
        ->assertSessionHasErrors(['publish' => 'Hosted apps are public for now. Publish to your domain or Tailscale to keep it private.']);
})->group('HOST-001');

test('hosting is offered only once a provider is set up, and needs somewhere to keep releases', function () {
    $targets = fn () => collect($this->actingAs($this->user)->get(route('projects.show', $this->project))->viewData('page')['props']['publication']['targets'])->keyBy('target');

    expect($targets()['hosting']['private'])->toBeNull()
        ->and($targets()['hosting']['unavailable'])->toBeNull();

    // Without an S3-compatible snapshot disk, releases go to an R2 bucket in the Cloudflare account.
    config(['filesystems.disks.releases.driver' => 'local']);
    expect($targets()['hosting']['unavailable'])->toBeNull();

    config(['hosting.providers.cloudflare.enabled' => false]);
    expect($targets()['hosting']['unavailable'])->toBe('Hosting needs somewhere to keep releases: turn on Cloudflare in Settings → Hosting, or use an S3-compatible SANDBOX_SNAPSHOT_DISK.');

    config(['hosting.providers.fly.enabled' => false]);
    expect($targets())->not->toHaveKey('hosting');
})->group('HOST-001', 'ADMIN-007');

test('without an S3-compatible snapshot disk, releases go to an R2 bucket made once with a key for it only', function () {
    fakeHostingProviders();
    config(['filesystems.disks.releases.driver' => 'local']);

    // Trying the new key would reach R2; record it instead.
    $releases = new class(app(HostingProviders::class)) extends ReleaseStorage
    {
        public int $waited = 0;

        protected function waitUntilReady(Filesystem $disk): void
        {
            $this->waited++;
        }
    };

    $disk = $releases->disk($this->project);
    $releases->disk($this->project);

    expect($disk->getConfig())->toMatchArray(['driver' => 's3', 'bucket' => 'onedrop-releases', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'key' => 'tok_1', 'region' => 'auto'])
        ->and($disk->temporaryUrl('hosting/1/1/release.tar.gz', now()->addHour()))->toStartWith('https://acct.r2.cloudflarestorage.com/onedrop-releases/hosting/1/1/release.tar.gz?')
        ->and(SystemSetting::query()->find(ReleaseStorage::SETTING)->getRawOriginal('value'))->not->toContain('tok_1')
        // Only a new key is tried before it's used.
        ->and($releases->waited)->toBe(1);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accounts/acct/r2/buckets') && $request['name'] === 'onedrop-releases');
})->group('HOST-001');

test('the workspace shows the deploy, its log and what the app has where', function () {
    fakeHostingProviders();
    publishToHosting($this->project, $this->user);

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('publication.hosting.deployment.status', 'live')
            ->where('publication.hosting.deployment.number', 1)
            ->where('publication.hosting.deployment.log', fn ($log) => str_contains($log, 'Live at https://'))
            ->where('publication.hosting.services.0.label', 'App')
            ->where('publication.hosting.services.0.provider', 'Fly.io')
            ->where('publication.hosting.services.0.owner', 'platform')
            ->where('publication.hosting.can_delete', false));
})->group('HOST-001', 'HOST-002');

test('unpublishing stops the machine and keeps the data', function () {
    fakeHostingProviders();
    publishToHosting($this->project, $this->user);

    $this->actingAs($this->user)->delete(route('projects.publication.destroy', $this->project))->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/machines/machine_app'));
    expect($this->project->hostedServices()->count())->toBe(2)
        ->and($this->project->fresh()->publish_status)->toBeNull();
})->group('HOST-001', 'HOST-002');

test('hosted data can be deleted once it is not published there, and goes with the project', function () {
    fakeHostingProviders();
    $this->sandboxes->manifest = ['static' => null, 'services' => ['postgres'], 'data' => [], 'storage' => false];
    $this->sandboxes->packed = 'release 2048';
    publishToHosting($this->project, $this->user);

    $this->actingAs($this->user)->delete(route('projects.hosting.destroy', $this->project))
        ->assertSessionHasErrors(['hosting' => 'Unpublish it first.']);

    $this->actingAs($this->user)->delete(route('projects.publication.destroy', $this->project));
    $app = $this->project->hostedServices()->where('kind', HostedServiceKind::App)->first();
    $this->actingAs($this->user)->delete(route('projects.hosting.destroy', $this->project))->assertSessionHasNoErrors();

    expect($this->project->hostedServices()->count())->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), "/apps/{$app->name}?force=true"));
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/projects/neon_1'));
})->group('HOST-002');

test('deleting a project deletes what it has at hosting providers', function () {
    fakeHostingProviders();
    publishToHosting($this->project, $this->user);
    $app = $this->project->hostedServices()->where('kind', HostedServiceKind::App)->first();

    app(DeleteProject::class)->handle($this->project->fresh());

    expect(HostedService::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), "/apps/{$app->name}?force=true"));
    // The app's deletion takes its volume with it.
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/volumes/'));
})->group('HOST-002');

test("an organization's own account is used instead of the install's, and its apps stay there", function () {
    fakeHostingProviders();
    $organization = $this->project->organization;
    $organization->forceFill(['hosting_accounts' => ['fly' => ['api_token' => 'fly-theirs', 'org_slug' => 'acme']]])->save();

    publishToHosting($this->project, $this->user);

    expect($this->project->hostedServices()->where('kind', HostedServiceKind::App)->first()->owner)->toBe(HostedService::OWNER_ORGANIZATION);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.machines.dev/v1/apps'
        && $request['org_slug'] === 'acme' && $request->header('Authorization')[0] === 'Bearer fly-theirs');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.machines.dev') && $request->header('Authorization')[0] === 'Bearer fly-platform');
})->group('HOST-003');

test('without an account for a service it needs, the deploy says who has to set one up', function () {
    fakeHostingProviders();
    config(['hosting.providers.upstash.enabled' => false]);
    $this->sandboxes->manifest = ['static' => null, 'services' => ['redis'], 'data' => [], 'storage' => false];

    publishToHosting($this->project, $this->user);

    expect($this->project->fresh()->publish_error)->toBe('Redis needs Upstash: an admin can set it up in Settings → Hosting, or your organization can connect its own account.')
        ->and($this->project->hostedServices()->count())->toBe(0);
})->group('HOST-002', 'HOST-003');

test("the builder's log is added to the deploy through its signed link only", function () {
    $deployment = Deployment::factory()->for($this->project)->create();

    $this->postJson(route('hosting.deployments.log', $deployment))->assertForbidden();

    $this->call('POST', URL::temporarySignedRoute('hosting.deployments.log', now()->addHour(), $deployment), [], [], [], ['CONTENT_TYPE' => 'text/plain'], "Fetching crane.\nPushed.")
        ->assertNoContent();

    expect($deployment->fresh()->log)->toContain("Builder log:\nFetching crane.\nPushed.");
})->group('HOST-001');

test("a hosted app's sandbox can sleep and isn't touched by a sandbox update", function () {
    $this->project->update(['publish_target' => PublishTarget::Hosting, 'publish_status' => PublishStatus::Live, 'publish_visibility' => PublishVisibility::Public, 'published_url' => 'https://bake-sale.fly.dev']);

    $inUse = (new ReflectionMethod(SuspendIdleSandboxes::class, 'inUse'))->invoke(app(SuspendIdleSandboxes::class), $this->project->sandbox);

    expect($inUse)->toBeFalse();
})->group('HOST-001');

test('a newer deploy replaces one still running', function () {
    $running = Deployment::factory()->for($this->project)->create();

    $this->project->update(['published_by' => $this->user->id]);
    Queue::fake();
    $next = app(Deployer::class)->start($this->project, $this->user);

    expect($running->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($running->fresh()->error)->toBe('A newer deploy replaced this one.')
        ->and($next->number)->toBe(2);
})->group('HOST-001');

test('organization members other than owners and admins do not see its hosting accounts', function () {
    $member = User::factory()->create();
    $organization = Organization::query()->find($this->project->organization_id);
    $organization->addMember($member, OrganizationRole::Member);

    $this->actingAs($member)->get(route('organizations.edit', $organization))
        ->assertInertia(fn ($page) => $page->where('hosting', null));
})->group('HOST-003');

test('an app with SQLite gets a backups bucket, and its machine backs the databases up with Litestream', function () {
    fakeHostingProviders();
    $this->sandboxes->manifest = ['static' => null, 'services' => [], 'data' => ['database/database.sqlite', '.onedrop/data'], 'sqlite' => ['database/database.sqlite', '.onedrop/data/jobs.sqlite'], 'storage' => false];

    publishToHosting($this->project, $this->user);

    $backup = $this->project->hostedServices()->where('kind', HostedServiceKind::Backup)->first();
    $env = appMachineRequest()['config']['env'];

    expect($backup->name)->toEndWith('-backups')
        ->and($backup->provider)->toBe('cloudflare')
        ->and($env['ONEDROP_SQLITE'])->toBe('database/database.sqlite:.onedrop/data/jobs.sqlite')
        ->and($env['ONEDROP_BACKUP_BUCKET'])->toBe($backup->name)
        ->and($env['ONEDROP_BACKUP_ENDPOINT'])->toBe('https://acct.r2.cloudflarestorage.com')
        ->and($env['LITESTREAM_ACCESS_KEY_ID'])->toBe('tok_1')
        ->and($env['LITESTREAM_SECRET_ACCESS_KEY'])->toBe(hash('sha256', 'token-value'))
        ->and($env['ONEDROP_LITESTREAM_VERSION'])->toBe(config('hosting.litestream_version'));

    // A key for that bucket only.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accounts/acct/tokens')
        && array_keys($request['policies'][0]['resources']) === ["com.cloudflare.edge.r2.bucket.acct_default_{$backup->name}"]);
})->group('HOST-008');

test('without a Cloudflare account, SQLite relies on the volume snapshots and the log says so', function () {
    fakeHostingProviders();
    config(['hosting.providers.cloudflare.enabled' => false]);
    $this->sandboxes->manifest = ['static' => null, 'services' => [], 'data' => ['database/database.sqlite'], 'sqlite' => ['database/database.sqlite'], 'storage' => false];

    publishToHosting($this->project, $this->user);

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and($this->project->hostedServices()->where('kind', HostedServiceKind::Backup)->exists())->toBeFalse()
        ->and(appMachineRequest()['config']['env'])->not->toHaveKey('ONEDROP_SQLITE')
        ->and($this->project->deployments()->first()->log)->toContain('rely on the volume\'s daily snapshots');
})->group('HOST-008');
