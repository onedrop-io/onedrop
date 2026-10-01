<?php

use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Models\AgentConnection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Gateway;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\Publishers;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    $this->tailscale = new FakePublisher;
    app()->instance(Publisher::class, $this->tailscale);

    $this->owner = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->owner)->create(['name' => 'Time Tracker']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://onedrop-project-1-x:8081']);
});

function publishToDomain(Project $project, User $user, string $visibility): Project
{
    test()->actingAs($user)
        ->post(route('projects.publication.store', $project), ['visibility' => $visibility, 'target' => 'domain'])
        ->assertRedirect(route('projects.show', $project));

    auth()->logout();

    return $project->fresh();
}

function appHost(Project $project): string
{
    return (string) parse_url((string) $project->published_url, PHP_URL_HOST);
}

test('publishing to the domain gives the project its own address there', function () {
    $project = publishToDomain($this->project, $this->owner, 'public');

    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->publish_target)->toBe(PublishTarget::Domain)
        ->and($project->published_url)->toBe("https://time-tracker-{$project->id}.onedrop.example.com")
        ->and($this->tailscale->published)->toBe([]);
})->group('PUB-002');

test('anyone can open a public app, without signing in', function () {
    $project = publishToDomain($this->project, $this->owner, 'public');

    $this->withHeaders(['X-Forwarded-Host' => appHost($project)])
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'onedrop-project-1-x:8081');
})->group('PUB-002');

test('a private app sends visitors to sign in, then back to the page they asked for', function () {
    $project = publishToDomain($this->project, $this->owner, 'private');

    $this->withHeaders(['X-Forwarded-Host' => appHost($project), 'X-Forwarded-Uri' => '/invoices?page=2'])
        ->get(route('sandbox-gateway.authorize'))
        ->assertRedirect(rtrim(config('app.url'), '/')."/projects/{$project->id}/open/app?path=%2Finvoices%3Fpage%3D2")
        ->assertHeaderMissing('X-OneDrop-Upstream');
})->group('PUB-002');

test('anyone in the project\'s organization can open a private app, not only the project\'s people', function () {
    $project = publishToDomain($this->project, $this->owner, 'private');
    $colleague = User::factory()->create();

    $enter = $this->actingAs($colleague)
        ->get(route('projects.gateway.open', [$project, 'app', 'path' => '/invoices']))
        ->assertRedirect();

    expect($enter->headers->get('Location'))->toStartWith("https://time-tracker-{$project->id}.onedrop.example.com/__onedrop/enter?");

    $target = app(Gateway::class)->parse(appHost($project));

    $this->withHeaders(['X-Forwarded-Host' => appHost($project)])
        ->withCookie(Gateway::COOKIE, app(Gateway::class)->pass($colleague->id, $target))
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'onedrop-project-1-x:8081');

    // Their preview and shell stay the project's own.
    $this->get(route('projects.gateway.open', [$project, 'preview']))->assertForbidden();
})->group('PUB-002');

test('an app that is unpublished, failed, or published to Tailscale is not served on the domain', function (Closure $change) {
    $project = publishToDomain($this->project, $this->owner, 'public');
    $host = appHost($project);
    $change($project);

    $this->withHeaders(['X-Forwarded-Host' => $host])->get(route('sandbox-gateway.authorize'))->assertNotFound();
    $this->get(route('sandbox-gateway.certificate', ['domain' => $host]))->assertNotFound();
})->with([
    'unpublished' => [fn (Project $project) => test()->actingAs($project->user)->delete(route('projects.publication.destroy', $project))],
    'failed' => [fn (Project $project) => $project->update(['publish_status' => PublishStatus::Failed])],
    'tailscale' => [fn (Project $project) => $project->update(['publish_target' => PublishTarget::Tailscale])],
])->group('PUB-002');

test('certificates are issued for live apps on the domain, and not for made-up names', function () {
    $project = publishToDomain($this->project, $this->owner, 'public');

    $this->get(route('sandbox-gateway.certificate', ['domain' => appHost($project)]))->assertOk();
    $this->get(route('sandbox-gateway.certificate', ['domain' => "something-else-{$project->id}.onedrop.example.com"]))->assertNotFound();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'time-tracker-999.onedrop.example.com']))->assertNotFound();
})->group('PUB-002');

test('a project named like a preview address gets an app- prefix, so it never takes over a preview', function () {
    $this->project->update(['name' => 'Preview']);
    $project = publishToDomain($this->project, $this->owner, 'public');

    expect($project->published_url)->toBe("https://app-preview-{$project->id}.onedrop.example.com")
        ->and(app(Gateway::class)->parse("preview-{$project->id}.onedrop.example.com"))->toBe(['kind' => 'preview', 'sandbox_id' => $project->id]);
})->group('PUB-002');

test('on a server both targets are offered, the domain first; elsewhere only Tailscale', function () {
    expect(collect(app(Publishers::class)->options())->pluck('target')->all())->toBe(['domain', 'tailscale'])
        ->and(app(Publishers::class)->default())->toBe(PublishTarget::Domain);

    config(['sandbox.gateway_domain' => null]);
    expect(collect(app(Publishers::class)->options())->pluck('target')->all())->toBe(['tailscale']);

    // Laravel Cloud: the Cloudflare Worker serves the domain instead of Caddy.
    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'worker-secret']);
    expect(collect(app(Publishers::class)->options())->pluck('target')->all())->toBe(['domain', 'tailscale']);
})->group('PUB-002');

test('behind the Cloudflare Worker, a public app gets the provider address and token, and a private one a sign-in redirect', function () {
    config(['sandbox.gateway_secret' => 'worker-secret']);
    $this->sandbox->update(['preview_url' => 'https://abc.preview.bl.run/?bl_preview_token=secret-token']);
    $project = publishToDomain($this->project, $this->owner, 'public');
    $worker = ['X-OneDrop-Gateway-Secret' => 'worker-secret', 'X-OneDrop-Gateway-Host' => appHost($project)];

    $this->withHeaders($worker)->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'https://abc.preview.bl.run')
        ->assertHeader('X-OneDrop-Upstream-Header', 'X-Blaxel-Preview-Token')
        ->assertHeader('X-OneDrop-Upstream-Token', 'secret-token');

    $project->update(['publish_visibility' => PublishVisibility::Private]);

    $this->withHeaders($worker)->get(route('sandbox-gateway.authorize'))
        ->assertRedirect(rtrim(config('app.url'), '/')."/projects/{$project->id}/open/app?path=%2F")
        ->assertHeaderMissing('X-OneDrop-Upstream');

    // Without the Worker's secret, nobody learns where it lives.
    $this->flushHeaders()->withHeaders(['X-OneDrop-Gateway-Host' => appHost($project)])->get(route('sandbox-gateway.authorize'))->assertNotFound();
})->group('PUB-002');

test('the publish panel says who private and public mean for each target', function () {
    $this->actingAs($this->owner)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('publication.targets.0.target', 'domain')
            ->where('publication.targets.0.private', 'People signed in to OneDrop')
            ->where('publication.targets.1.target', 'tailscale')
            ->where('publication.targets.1.private', "People on your team's tailnet")
            ->where('publication.target', null));
})->group('PUB-002');

test('moving a published project to the other target takes it down from the first', function () {
    $this->actingAs($this->owner)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'tailscale']);

    expect($this->tailscale->published)->toHaveKey($this->project->id);

    $project = publishToDomain($this->project, $this->owner, 'private');

    expect($this->tailscale->published)->not->toHaveKey($project->id)
        ->and($project->publish_target)->toBe(PublishTarget::Domain)
        ->and($project->publish_visibility)->toBe(PublishVisibility::Private)
        ->and($project->published_url)->toBe("https://time-tracker-{$project->id}.onedrop.example.com");
})->group('PUB-002');

test('the domain cannot be chosen without a server install', function () {
    config(['sandbox.gateway_domain' => null]);

    $this->actingAs($this->owner)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'public', 'target' => 'domain'])
        ->assertSessionHasErrors('publish');

    expect($this->project->fresh()->publish_status)->toBeNull();
})->group('PUB-002');

test('signing in to a private app lands on its address with its own cookie', function () {
    $project = publishToDomain($this->project, $this->owner, 'private');
    $host = appHost($project);
    $colleague = User::factory()->create();

    $enterUrl = $this->actingAs($colleague)
        ->get(route('projects.gateway.open', [$project, 'app', 'path' => '/invoices?page=2']))
        ->headers->get('Location');

    auth()->logout();
    $enter = $this->get($enterUrl);

    $enter->assertRedirect("https://{$host}/invoices?page=2")->assertCookie(Gateway::COOKIE)->assertCookieMissing(config('session.cookie'));
    $cookie = collect($enter->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Gateway::COOKIE);

    $this->withUnencryptedCookie(Gateway::COOKIE, $cookie->getValue())
        ->withHeaders(['X-Forwarded-Host' => $host])
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'onedrop-project-1-x:8081');

    // That cookie is for the app only, not the project's preview.
    $this->withUnencryptedCookie(Gateway::COOKIE, $cookie->getValue())
        ->withHeaders(['X-Forwarded-Host' => "preview-{$this->sandbox->id}.onedrop.example.com"])
        ->get(route('sandbox-gateway.authorize'))
        ->assertUnauthorized();
})->group('PUB-002');

test('a project not published to the domain cannot be opened as an app', function () {
    $this->actingAs($this->owner)
        ->get(route('projects.gateway.open', [$this->project, 'app']))
        ->assertNotFound();
})->group('PUB-002');

test('once published, the workspace says who can open it there', function (string $target, string $visibility, string $audience) {
    $this->actingAs($this->owner)->post(route('projects.publication.store', $this->project), ['visibility' => $visibility, 'target' => $target]);

    $this->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('publication.audience', $audience)->where('publication.target', $target));
})->with([
    'domain, private' => ['domain', 'private', 'People signed in to OneDrop'],
    'domain, public' => ['domain', 'public', 'Anyone on the internet with the URL'],
    'tailscale, private' => ['tailscale', 'private', "People on your team's tailnet"],
])->group('PUB-002');

test('a private app does not open for people outside its organization', function () {
    $project = publishToDomain($this->project, $this->owner, 'private');
    config(['app.multi_tenant' => true]);
    $outsider = User::factory()->has(AgentConnection::factory())->create();
    Organization::factory()->create()->addMember($outsider);

    $this->actingAs($outsider)->get(route('projects.gateway.open', [$project, 'app']))->assertNotFound();

    $this->withHeaders(['X-Forwarded-Host' => appHost($project)])
        ->withCookie(Gateway::COOKIE, app(Gateway::class)->pass($outsider->id, app(Gateway::class)->parse(appHost($project))))
        ->get(route('sandbox-gateway.authorize'))
        ->assertForbidden()
        ->assertHeaderMissing('X-OneDrop-Upstream');
})->group('ORG-007');
