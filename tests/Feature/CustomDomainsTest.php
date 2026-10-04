<?php

use App\Enums\DomainStatus;
use App\Enums\PublishVisibility;
use App\Jobs\CheckProjectDomain;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Domains\DomainDns;
use App\Sandbox\Domains\ProjectDomains;
use App\Sandbox\Gateway;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    Queue::fake([CheckProjectDomain::class]);
    app()->instance(Publisher::class, new FakePublisher);

    // Where each name points; the server's own domain is at 203.0.113.10.
    $this->dns = ['onedrop.example.com' => ['203.0.113.10', '2001:db8::10']];
    $this->mock(DomainDns::class)->shouldReceive('addresses')->andReturnUsing(fn (string $host) => $this->dns[$host] ?? []);

    $this->owner = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->owner)->create(['name' => 'Bake Sale']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://onedrop-project-1-x:8081']);
});

/**
 * Publish the project to the server's domain, then add domains to it as its owner.
 */
function publishWithDomains(Project $project, string $visibility, string ...$hostnames): Project
{
    test()->actingAs($project->user)->post(route('projects.publication.store', $project), ['visibility' => $visibility, 'target' => 'domain']);

    foreach ($hostnames as $hostname) {
        test()->postJson(route('projects.domains.store', $project), ['hostname' => $hostname])->assertOk();
    }

    auth()->logout();

    return $project->fresh();
}

function checkDomain(Project $project, string $hostname): void
{
    $domain = $project->domains()->where('hostname', $hostname)->firstOrFail();

    test()->actingAs($project->user)->postJson(route('projects.domains.check', [$project, $domain]))->assertOk();
    auth()->logout();
}

test('adding a subdomain shows a CNAME to the server, and a root domain its addresses', function () {
    $this->actingAs($this->owner)->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'domain']);

    $this->postJson(route('projects.domains.store', $this->project), ['hostname' => 'https://Shop.Example.com/'])
        ->assertOk()
        ->assertJsonPath('domains.0.hostname', 'shop.example.com')
        ->assertJsonPath('domains.0.primary', true)
        ->assertJsonPath('domains.0.status', 'pending')
        ->assertJsonPath('domains.0.records', [['type' => 'CNAME', 'name' => 'shop.example.com', 'value' => 'onedrop.example.com']])
        ->assertJsonPath('redirects', true);

    $this->postJson(route('projects.domains.store', $this->project), ['hostname' => 'example.com'])
        ->assertOk()
        ->assertJsonPath('domains.1.primary', false)
        ->assertJsonPath('domains.1.records', [
            ['type' => 'A', 'name' => 'example.com', 'value' => '203.0.113.10'],
            ['type' => 'AAAA', 'name' => 'example.com', 'value' => '2001:db8::10'],
        ]);

    Queue::assertPushed(CheckProjectDomain::class, 2);
})->group('DOM-001');

test('a domain that is not a domain name, is the app builder\'s own, or is taken is refused', function (string $hostname, string $message) {
    $other = Project::factory()->for($this->owner)->create();
    ProjectDomain::factory()->for($other)->create(['hostname' => 'taken.example.com']);
    ProjectDomain::factory()->for($this->project)->create(['hostname' => 'mine.example.com']);

    $this->actingAs($this->owner)
        ->postJson(route('projects.domains.store', $this->project), ['hostname' => $hostname])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hostname' => $message]);
})->with([
    'not a name' => ['not a domain', 'Enter a domain name'],
    'an address' => ['203.0.113.10', 'Enter a domain name'],
    'no dot' => ['localhost', 'Enter a domain name'],
    'the server\'s domain' => ['onedrop.example.com', 'own domain'],
    'under the server\'s domain' => ['preview-1.onedrop.example.com', 'own domain'],
    'another project\'s' => ['taken.example.com', 'connected to another project'],
    'already added' => ['Mine.example.com', 'already added'],
])->group('DOM-001');

test('a waiting domain says where its DNS points, and goes active once it points at the server', function () {
    $project = publishWithDomains($this->project, 'public', 'shop.example.com');
    $this->dns['shop.example.com'] = ['198.51.100.7'];

    checkDomain($project, 'shop.example.com');

    $domain = $project->domains()->first();
    expect($domain->status)->toBe(DomainStatus::Pending)
        ->and($domain->error)->toBe('shop.example.com points at 198.51.100.7, not this server (203.0.113.10, 2001:db8::10).');

    $this->dns['shop.example.com'] = ['203.0.113.10'];
    checkDomain($project, 'shop.example.com');

    expect($domain->fresh()->status)->toBe(DomainStatus::Active)
        ->and($project->fresh()->published_url)->toBe('https://shop.example.com')
        ->and($project->fresh()->published_default_url)->toBe("https://bake-sale-{$project->id}.onedrop.example.com");
})->group('DOM-001', 'DOM-002');

test('waiting domains are checked often at first, then less often, and not after two days', function () {
    config(['queue.default' => 'database']);
    $project = publishWithDomains($this->project, 'public', 'shop.example.com');
    $domain = $project->domains()->first();
    $since = $domain->checking_since->getTimestamp();

    (new CheckProjectDomain($domain, $since))->handle(app(ProjectDomains::class));
    Queue::assertPushed(CheckProjectDomain::class, fn (CheckProjectDomain $job) => $job->delay->getTimestamp() === now()->addSeconds(30)->getTimestamp());

    $this->travel(11)->minutes();
    (new CheckProjectDomain($domain, $since))->handle(app(ProjectDomains::class));
    Queue::assertPushed(CheckProjectDomain::class, fn (CheckProjectDomain $job) => $job->delay->getTimestamp() === now()->addSeconds(300)->getTimestamp());

    $this->travel(2)->days();
    $count = Queue::pushed(CheckProjectDomain::class)->count();
    (new CheckProjectDomain($domain, $since))->handle(app(ProjectDomains::class));
    expect(Queue::pushed(CheckProjectDomain::class)->count())->toBe($count);

    // A newer run of checks (Check now) replaces this one.
    $domain->update(['checking_since' => now()]);
    $checked = $domain->fresh()->checked_at;
    $this->travel(1)->minutes();
    (new CheckProjectDomain($domain, $since))->handle(app(ProjectDomains::class));
    expect($domain->fresh()->checked_at->equalTo($checked))->toBeTrue();
})->group('DOM-001');

test('the primary domain is the app\'s address; its own address and other domains redirect there, keeping the path', function () {
    $project = publishWithDomains($this->project, 'public', 'example.com', 'www.example.com');
    $this->dns['example.com'] = ['203.0.113.10'];
    $this->dns['www.example.com'] = ['203.0.113.10'];
    checkDomain($project, 'example.com');
    checkDomain($project, 'www.example.com');
    $own = "bake-sale-{$project->id}.onedrop.example.com";

    $this->withHeaders(['X-Forwarded-Host' => 'example.com'])->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'onedrop-project-1-x:8081');

    foreach (['www.example.com', $own] as $host) {
        $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => '/menu?day=2'])->get(route('sandbox-gateway.authorize'))
            ->assertRedirect('https://example.com/menu?day=2');
    }
})->group('DOM-002');

test('making another domain primary moves the address, and removing the primary falls back', function () {
    $project = publishWithDomains($this->project, 'public', 'example.com', 'www.example.com');
    $this->dns['www.example.com'] = ['203.0.113.10'];
    checkDomain($project, 'www.example.com');
    $www = $project->domains()->where('hostname', 'www.example.com')->first();

    // The primary isn't active yet, so the app stays at its own address and every domain serves it.
    expect($project->fresh()->published_url)->toBe("https://bake-sale-{$project->id}.onedrop.example.com");
    $this->withHeaders(['X-Forwarded-Host' => 'www.example.com'])->get(route('sandbox-gateway.authorize'))->assertOk();

    $this->actingAs($this->owner)->patchJson(route('projects.domains.update', [$project, $www]), ['primary' => true])
        ->assertOk()
        ->assertJsonPath('domains.0.hostname', 'www.example.com');
    expect($project->fresh()->published_url)->toBe('https://www.example.com');

    $this->deleteJson(route('projects.domains.destroy', [$project, $www]))->assertOk();
    expect($project->fresh()->published_url)->toBe("https://bake-sale-{$project->id}.onedrop.example.com")
        ->and($project->domains()->where('hostname', 'example.com')->value('primary'))->toBeTrue();
})->group('DOM-002');

test('a private app on a custom domain asks visitors to sign in, then lands them there with its own cookie', function () {
    $project = publishWithDomains($this->project, 'private', 'shop.example.com');
    $this->dns['shop.example.com'] = ['203.0.113.10'];
    checkDomain($project, 'shop.example.com');

    $this->withHeaders(['X-Forwarded-Host' => 'shop.example.com', 'X-Forwarded-Uri' => '/orders'])->get(route('sandbox-gateway.authorize'))
        ->assertRedirect(rtrim(config('app.url'), '/')."/projects/{$project->id}/open/app?path=%2Forders");

    $enter = $this->actingAs($this->owner)->get(route('projects.gateway.open', [$project, 'app', 'path' => '/orders']));
    $location = (string) $enter->headers->get('Location');
    expect($location)->toStartWith('https://shop.example.com/__onedrop/enter?');
    auth()->logout();

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $this->flushHeaders();
    $response = $this->call('GET', 'http://shop.example.com/__onedrop/enter', $query);
    $response->assertRedirect('https://shop.example.com/orders');

    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === Gateway::COOKIE);
    expect($cookie->getDomain())->toBeNull();

    $this->withHeaders(['X-Forwarded-Host' => 'shop.example.com'])
        ->withUnencryptedCookie(Gateway::COOKIE, $cookie->getValue())
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk();
})->group('DOM-003');

test('Caddy gets certificates only for domains a project added', function () {
    publishWithDomains($this->project, 'public', 'shop.example.com');

    $this->get(route('sandbox-gateway.certificate', ['domain' => 'shop.example.com']))->assertOk();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'random.example.net']))->assertNotFound();
})->group('DOM-001');

test('a domain is not served while the app is unpublished, and stays for the next publish', function () {
    $project = publishWithDomains($this->project, 'public', 'shop.example.com');
    $this->dns['shop.example.com'] = ['203.0.113.10'];
    checkDomain($project, 'shop.example.com');

    $this->actingAs($this->owner)->delete(route('projects.publication.destroy', $project));
    $this->withHeaders(['X-Forwarded-Host' => 'shop.example.com'])->get(route('sandbox-gateway.authorize'))->assertNotFound();
    expect($project->domains()->count())->toBe(1);

    $this->post(route('projects.publication.store', $project), ['visibility' => 'public', 'target' => 'domain']);
    expect($project->fresh()->published_url)->toBe('https://shop.example.com');
})->group('DOM-004');

test('Tailscale cannot have a custom domain, and the panel says why', function () {
    config(['sandbox.gateway_domain' => null]);
    $this->project->update(['publish_target' => 'tailscale']);

    $this->actingAs($this->owner)->postJson(route('projects.domains.store', $this->project), ['hostname' => 'shop.example.com'])
        ->assertOk()
        ->assertJsonPath('domains.0.status', 'not_connected')
        ->assertJsonPath('unavailable', "Tailscale can't use your own domain. Publish to Your domain or Hosting to connect it.");

    Queue::assertNothingPushed();
})->group('DOM-001');

test('behind the Worker, a domain becomes a Cloudflare for SaaS custom hostname', function () {
    config([
        'sandbox.gateway_secret' => 'worker-secret',
        'sandbox.gateway_domains' => ['zone_id' => 'zone-1', 'api_token' => 'cf-gateway', 'target' => 'domains.onedrop.example.com'],
    ]);
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/custom_hostnames' => Http::response(['success' => true, 'result' => ['id' => 'ch-1']]),
        'api.cloudflare.com/client/v4/zones/zone-1/custom_hostnames/ch-1' => Http::sequence()
            ->push(['success' => true, 'result' => ['id' => 'ch-1', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation'], 'verification_errors' => ['custom hostname does not CNAME to this zone.']]])
            ->push(['success' => true, 'result' => ['id' => 'ch-1', 'status' => 'active', 'ssl' => ['status' => 'active']]])
            ->push(['success' => true, 'result' => []]),
    ]);

    $project = publishWithDomains($this->project, 'public', 'shop.example.com');
    $domain = $project->domains()->first();

    expect($domain->via)->toBe('cloudflare-saas')
        ->and($domain->external_id)->toBe('ch-1')
        ->and($domain->records)->toBe([['type' => 'CNAME', 'name' => 'shop.example.com', 'value' => 'domains.onedrop.example.com']]);

    checkDomain($project, 'shop.example.com');
    expect($domain->fresh()->error)->toBe('custom hostname does not CNAME to this zone.');

    checkDomain($project, 'shop.example.com');
    expect($domain->fresh()->status)->toBe(DomainStatus::Active);

    $worker = ['X-OneDrop-Gateway-Secret' => 'worker-secret', 'X-OneDrop-Gateway-Host' => 'shop.example.com'];
    $this->withHeaders($worker)->get(route('sandbox-gateway.authorize'))->assertOk();

    $this->actingAs($this->owner)->deleteJson(route('projects.domains.destroy', [$project, $domain]))->assertOk();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/zones/zone-1/custom_hostnames/ch-1'));
})->group('DOM-001');

test('behind the Worker without Cloudflare for SaaS set up, the panel says an admin has to', function () {
    config(['sandbox.gateway_secret' => 'worker-secret']);
    $this->project->update(['publish_target' => 'domain']);

    $this->actingAs($this->owner)->getJson(route('projects.domains.index', $this->project))
        ->assertOk()
        ->assertJsonPath('unavailable', 'Custom domains aren\'t set up on this install yet: an admin needs to turn on Cloudflare for SaaS for the preview gateway.');
})->group('DOM-001');

test('people who can see the project can see its domains; only those who can change it can change them', function () {
    $domain = ProjectDomain::factory()->for($this->project)->create();
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($stranger)->getJson(route('projects.domains.index', $this->project))->assertForbidden();
    $this->postJson(route('projects.domains.store', $this->project), ['hostname' => 'x.example.com'])->assertForbidden();
    $this->deleteJson(route('projects.domains.destroy', [$this->project, $domain]))->assertForbidden();

    // A domain only belongs to its own project's routes.
    $other = Project::factory()->for($this->owner)->create();
    $this->actingAs($this->owner)->deleteJson(route('projects.domains.destroy', [$other, $domain]))->assertNotFound();
})->group('DOM-001');

test('the public flag still applies on a custom domain', function () {
    $project = publishWithDomains($this->project, 'public', 'shop.example.com');
    $this->dns['shop.example.com'] = ['203.0.113.10'];
    checkDomain($project, 'shop.example.com');
    $project->update(['publish_visibility' => PublishVisibility::Private]);

    $this->withHeaders(['X-Forwarded-Host' => 'shop.example.com'])->get(route('sandbox-gateway.authorize'))->assertRedirect();
})->group('DOM-003');
