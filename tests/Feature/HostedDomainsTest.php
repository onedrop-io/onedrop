<?php

use App\Actions\DeleteProject;
use App\Enums\DomainStatus;
use App\Enums\HostedServiceKind;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Jobs\CheckProjectDomain;
use App\Models\AgentConnection;
use App\Models\Deployment;
use App\Models\HostedService;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Domains\ProjectDomains;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'hosting.providers.fly' => ['enabled' => true, 'api_token' => 'fly-platform', 'org_slug' => 'onedrop', 'region' => 'iad'],
        'hosting.providers.cloudflare' => ['enabled' => true, 'account_id' => 'acct', 'api_token' => 'cf-token'],
    ]);
    Queue::fake([CheckProjectDomain::class]);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create([
        'name' => 'Bake Sale',
        'publish_target' => PublishTarget::Hosting,
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Public,
        'published_url' => 'https://onedrop-bake-sale.fly.dev',
        'published_default_url' => 'https://onedrop-bake-sale.fly.dev',
    ]);
});

/**
 * Fly's answer to checking a certificate.
 *
 * @return array<string, mixed>
 */
function flyCertificate(string $status, array $errors = []): array
{
    return [
        'hostname' => 'shop.example.com',
        'status' => $status,
        'certificates' => [],
        'dns_requirements' => ['a' => ['66.241.124.1'], 'aaaa' => ['2a09:8280:1::1'], 'cname' => 'onedrop-bake-sale.fly.dev'],
        'validation_errors' => $errors,
    ];
}

test('a hosted app with a server gets a Fly certificate, with the records Fly asks for', function () {
    Deployment::factory()->for($this->project)->live()->create();
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::App, 'name' => 'onedrop-bake-sale']);
    Http::fake([
        'api.machines.dev/v1/apps/onedrop-bake-sale/certificates/acme' => Http::response(['hostname' => 'shop.example.com']),
        'api.machines.dev/v1/apps/onedrop-bake-sale/certificates/shop.example.com/check' => Http::sequence()
            ->push(flyCertificate('awaiting_configuration'))
            ->push(flyCertificate('awaiting_configuration', [['message' => 'shop.example.com has no CNAME to onedrop-bake-sale.fly.dev']]))
            ->push(flyCertificate('active')),
        'api.machines.dev/v1/apps/onedrop-bake-sale/certificates/shop.example.com' => Http::response([]),
    ]);

    $this->actingAs($this->user)->postJson(route('projects.domains.store', $this->project), ['hostname' => 'shop.example.com'])
        ->assertOk()
        ->assertJsonPath('domains.0.records', [['type' => 'CNAME', 'name' => 'shop.example.com', 'value' => 'onedrop-bake-sale.fly.dev']])
        ->assertJsonPath('redirects', false);

    $domain = $this->project->domains()->first();
    $this->postJson(route('projects.domains.check', [$this->project, $domain]))
        ->assertJsonPath('domains.0.error', 'shop.example.com has no CNAME to onedrop-bake-sale.fly.dev');

    $this->postJson(route('projects.domains.check', [$this->project, $domain]))->assertJsonPath('domains.0.status', 'active');
    expect($this->project->fresh()->published_url)->toBe('https://shop.example.com');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/certificates/acme') && $request['hostname'] === 'shop.example.com');

    // Deleting the project takes the domain off Fly.
    Http::fake(['*' => Http::response([])]);
    app(DeleteProject::class)->handle($this->project);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/apps/onedrop-bake-sale/certificates/shop.example.com'));
})->group('DOM-001', 'DOM-004');

test('a root domain on a hosted server app gets Fly\'s A and AAAA records', function () {
    Deployment::factory()->for($this->project)->live()->create();
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::App, 'name' => 'onedrop-bake-sale']);
    Http::fake([
        'api.machines.dev/v1/apps/onedrop-bake-sale/certificates/acme' => Http::response([]),
        'api.machines.dev/v1/apps/onedrop-bake-sale/certificates/example.com/check' => Http::response(flyCertificate('awaiting_configuration')),
    ]);

    $this->actingAs($this->user)->postJson(route('projects.domains.store', $this->project), ['hostname' => 'example.com'])
        ->assertJsonPath('domains.0.records', [
            ['type' => 'A', 'name' => 'example.com', 'value' => '66.241.124.1'],
            ['type' => 'AAAA', 'name' => 'example.com', 'value' => '2a09:8280:1::1'],
        ]);
})->group('DOM-001');

test('a hosted front end gets a Workers custom domain when its DNS is in the same Cloudflare account', function () {
    Deployment::factory()->for($this->project)->live()->create(['kind' => 'static']);
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::Site, 'provider' => 'cloudflare', 'name' => 'onedrop-bake-sale']);
    Http::fake([
        'api.cloudflare.com/client/v4/zones?name=shop.example.com*' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/zones?name=example.com*' => Http::response(['success' => true, 'result' => [['id' => 'zone-9']]]),
        'api.cloudflare.com/client/v4/accounts/acct/workers/domains' => Http::response(['success' => true, 'result' => ['id' => 'wd-1']]),
    ]);

    $this->actingAs($this->user)->postJson(route('projects.domains.store', $this->project), ['hostname' => 'shop.example.com'])
        ->assertOk()
        ->assertJsonPath('domains.0.records', []);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['hostname'] === 'shop.example.com' && $request['service'] === 'onedrop-bake-sale' && $request['zone_id'] === 'zone-9');

    $domain = $this->project->domains()->first();
    $this->postJson(route('projects.domains.check', [$this->project, $domain]))->assertJsonPath('domains.0.status', 'active');
})->group('DOM-001');

test('a hosted front end whose domain is elsewhere says to add it to that Cloudflare account', function () {
    Deployment::factory()->for($this->project)->live()->create(['kind' => 'static']);
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::Site, 'provider' => 'cloudflare', 'name' => 'onedrop-bake-sale']);
    Http::fake(['api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => []])]);

    $this->actingAs($this->user)->postJson(route('projects.domains.store', $this->project), ['hostname' => 'shop.example.com'])
        ->assertOk()
        ->assertJsonPath('domains.0.status', 'not_connected')
        ->assertJsonPath('domains.0.error', 'shop.example.com isn\'t in the Cloudflare account this app is hosted in. Add the domain to that account (Cloudflare → Add a domain), then check again.');
})->group('DOM-001');

test('before the first deploy, a hosted project\'s domain waits for it', function () {
    $this->project->update(['publish_status' => PublishStatus::Publishing]);

    $this->actingAs($this->user)->postJson(route('projects.domains.store', $this->project), ['hostname' => 'shop.example.com'])
        ->assertJsonPath('domains.0.status', 'not_connected')
        ->assertJsonPath('domains.0.error', 'Your domain connects after the first deploy to Hosting.');
})->group('DOM-001');

test('moving a project to another target moves its domains there', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    Deployment::factory()->for($this->project)->live()->create();
    HostedService::factory()->for($this->project)->create(['kind' => HostedServiceKind::App, 'name' => 'onedrop-bake-sale']);
    $domain = $this->project->domains()->create(['hostname' => 'shop.example.com', 'primary' => true, 'status' => DomainStatus::Active, 'via' => 'fly']);
    Http::fake(['*' => Http::response([])]);

    $this->project->update(['publish_target' => PublishTarget::Domain]);
    app(ProjectDomains::class)->sync($this->project);

    expect($domain->fresh())
        ->via->toBe('caddy')
        ->status->toBe(DomainStatus::Pending)
        ->records->toBe([['type' => 'CNAME', 'name' => 'shop.example.com', 'value' => 'onedrop.example.com']]);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/certificates/shop.example.com'));
})->group('DOM-004');
