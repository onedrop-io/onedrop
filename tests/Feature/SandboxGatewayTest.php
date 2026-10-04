<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Gateway;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);

    $this->owner = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->owner)->create();
    $this->sandbox = Sandbox::factory()->for($this->project)->create([
        'preview_url' => 'http://127.0.0.1:32800',
        'shell_url' => 'http://127.0.0.1:32799',
    ]);
});

function gatewayAuth(string $host, ?User $user = null, ?string $pass = null): TestResponse
{
    $request = test()->withHeaders(['X-Forwarded-Host' => $host]);

    if ($user) {
        $target = app(Gateway::class)->parse($host);
        $request->withCookie(Gateway::COOKIE, app(Gateway::class)->pass($user->id, $target ?? ['kind' => 'preview', 'sandbox_id' => 0]));
    } elseif ($pass !== null) {
        $request->withCookie(Gateway::COOKIE, $pass);
    }

    return $request->get(route('sandbox-gateway.authorize'));
}

test('the owner is routed to their sandbox preview and shell', function (string $kind, string $upstream) {
    gatewayAuth("{$kind}-{$this->sandbox->id}.onedrop.example.com", $this->owner)
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', $upstream);
})->with([
    'preview' => ['preview', '127.0.0.1:32800'],
    'shell' => ['shell', '127.0.0.1:32799'],
])->group('GW-001');

test('the app login cookie alone does not open a preview', function () {
    $this->actingAs($this->owner);

    gatewayAuth("shell-{$this->sandbox->id}.onedrop.example.com")
        ->assertUnauthorized()
        ->assertHeaderMissing('X-OneDrop-Upstream')
        ->assertHeader('X-OneDrop-Gateway', 'login-required; reason=no-cookie')
        ->assertSee('Open this preview from the app builder')
        ->assertSee(rtrim(config('app.url'), '/')."/projects/{$this->project->id}/open/shell", escape: false);
})->group('GW-001');

test('an expired or foreign pass is refused', function (Closure $pass) {
    gatewayAuth("preview-{$this->sandbox->id}.onedrop.example.com", pass: $pass($this))
        ->assertUnauthorized()
        ->assertHeader('X-OneDrop-Gateway', 'login-required; reason=expired')
        ->assertSee('has expired');
})->with([
    'garbage' => [fn () => 'not-a-pass'],
    'expired' => [function ($test) {
        $pass = app(Gateway::class)->pass($test->owner->id, ['kind' => 'preview', 'sandbox_id' => $test->sandbox->id]);
        test()->travel(Gateway::PASS_MINUTES + 1)->minutes();

        return $pass;
    }],
    'for another sandbox' => [fn ($test) => app(Gateway::class)->pass($test->owner->id, ['kind' => 'preview', 'sandbox_id' => $test->sandbox->id + 1])],
    'for the shell' => [fn ($test) => app(Gateway::class)->pass($test->owner->id, ['kind' => 'shell', 'sandbox_id' => $test->sandbox->id])],
])->group('GW-001');

test('other users are refused', function () {
    gatewayAuth("shell-{$this->sandbox->id}.onedrop.example.com", User::factory()->create())
        ->assertForbidden()
        ->assertHeaderMissing('X-OneDrop-Upstream');
})->group('GW-001');

test('admins can open any sandbox', function () {
    gatewayAuth("preview-{$this->sandbox->id}.onedrop.example.com", User::factory()->admin()->create())->assertOk();
})->group('GW-001');

test('unknown hosts, sandboxes and stopped sandboxes are not found', function (Closure $host) {
    gatewayAuth($host($this->sandbox), $this->owner)->assertNotFound();
})->with([
    'wrong domain' => [fn ($sandbox) => "preview-{$sandbox->id}.evil.com"],
    'unknown kind' => [fn ($sandbox) => "db-{$sandbox->id}.onedrop.example.com"],
    'missing sandbox' => [fn () => 'preview-999999.onedrop.example.com'],
    'stopped sandbox' => [function ($sandbox) {
        $sandbox->update(['status' => SandboxStatus::Paused]);

        return "preview-{$sandbox->id}.onedrop.example.com";
    }],
])->group('GW-001');

test('opening a preview hands the browser to that address, which sets its own cookie', function () {
    $open = $this->actingAs($this->owner)->get(route('projects.gateway.open', [$this->project, 'preview', 'path' => '/contacts?page=2']));
    $enterUrl = $open->assertRedirect()->headers->get('Location');

    expect($enterUrl)->toStartWith("https://preview-{$this->sandbox->id}.onedrop.example.com/__onedrop/enter?");

    // A fresh browser on the preview address: no app session involved.
    auth()->logout();
    $enter = $this->get($enterUrl);

    $enter->assertRedirect("https://preview-{$this->sandbox->id}.onedrop.example.com/contacts?page=2")->assertCookie(Gateway::COOKIE)->assertCookieMissing(config('session.cookie'));
    $cookie = collect($enter->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Gateway::COOKIE);
    expect($cookie->getDomain())->toBeNull()->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')->and($cookie->isPartitioned())->toBeFalse();

    // The browser sends back exactly what the address set.
    $this->withUnencryptedCookie(Gateway::COOKIE, $cookie->getValue())
        ->withHeaders(['X-Forwarded-Host' => "preview-{$this->sandbox->id}.onedrop.example.com"])
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk();
})->group('GW-001');

test('hand-off tokens only work briefly, on their own address', function () {
    $url = app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner);
    $token = parse_url($url, PHP_URL_QUERY);

    $this->get("https://shell-{$this->sandbox->id}.onedrop.example.com/__onedrop/enter?{$token}")->assertUnauthorized();
    $this->get("https://onedrop.example.com/__onedrop/enter?{$token}")->assertUnauthorized();

    $this->travel(Gateway::TOKEN_SECONDS + 1)->seconds();
    $this->get($url)->assertUnauthorized()->assertCookieMissing(Gateway::COOKIE);
})->group('GW-001');

test('the hand-off only redirects within the address', function (string $path) {
    $url = app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner, $path);

    $this->get($url)->assertRedirect("https://preview-{$this->sandbox->id}.onedrop.example.com/");
})->with(['//evil.com', 'https://evil.com', '/\\evil.com'])->group('GW-001');

test('behind the internal plain-http hop, the hand-off still lands on https', function () {
    $url = str_replace('https://', 'http://', app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner));

    $enter = $this->get($url)->assertRedirect("https://preview-{$this->sandbox->id}.onedrop.example.com/");

    expect(collect($enter->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Gateway::COOKIE)->isSecure())->toBeTrue();
})->group('GW-001');

test('the reopen link points at the app, not the preview host', function () {
    config(['app.url' => 'https://onedrop.example.com']);

    $this->withHeaders(['Host' => "preview-{$this->sandbox->id}.onedrop.example.com"])
        ->get("http://preview-{$this->sandbox->id}.onedrop.example.com/__onedrop/enter?token=bad")
        ->assertUnauthorized()
        ->assertSee("https://onedrop.example.com/projects/{$this->project->id}/open/preview", escape: false);
})->group('GW-001');

test('only people who can see the project can open it', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->get(route('projects.gateway.open', [$this->project, 'preview']))
        ->assertForbidden();

    auth()->logout();
    $this->get(route('projects.gateway.open', [$this->project, 'preview']))->assertRedirect(route('login'));
})->group('GW-001');

test('certificates are only issued for existing sandbox hosts', function () {
    $this->get(route('sandbox-gateway.certificate', ['domain' => "preview-{$this->sandbox->id}.onedrop.example.com"]))->assertOk();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'preview-999999.onedrop.example.com']))->assertNotFound();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'anything.onedrop.example.com']))->assertNotFound();
})->group('GW-001');

test('the workspace shows gateway urls on a server and local urls on a laptop', function () {
    $this->actingAs($this->owner)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('sandbox.preview_url', route('projects.gateway.open', [$this->project, 'preview']))
            ->where('sandbox.shell_url', route('projects.gateway.open', [$this->project, 'shell'])));

    config(['sandbox.gateway_domain' => null]);

    $this->actingAs($this->owner)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('sandbox.preview_url', 'http://127.0.0.1:32800'));
})->group('GW-001');

test('the gateway is off without a domain', function () {
    config(['sandbox.gateway_domain' => null]);

    gatewayAuth("preview-{$this->sandbox->id}.onedrop.example.com", $this->owner)->assertNotFound();
    $this->actingAs($this->owner)->get(route('projects.gateway.open', [$this->project, 'preview']))->assertNotFound();
})->group('GW-001');

/**
 * A request as the Cloudflare Worker sends it: the shared secret, and the address it's serving.
 */
function workerAuth(string $host, ?User $user = null, string $secret = 'worker-secret'): TestResponse
{
    $request = test()->withHeaders([Gateway::SECRET_HEADER => $secret, Gateway::HOST_HEADER => $host]);

    if ($user) {
        $request->withCookie(Gateway::COOKIE, app(Gateway::class)->pass($user->id, app(Gateway::class)->parse($host)));
    }

    return $request->get(route('sandbox-gateway.authorize'));
}

test('the worker gets the provider address and token to forward to, never the browser', function (string $url, string $upstream, string $header, string $token) {
    config(['sandbox.gateway_secret' => 'worker-secret']);
    $this->sandbox->update(['preview_url' => $url]);

    workerAuth("preview-{$this->sandbox->id}.onedrop.example.com", $this->owner)
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', $upstream)
        ->assertHeader('X-OneDrop-Upstream-Header', $header)
        ->assertHeader('X-OneDrop-Upstream-Token', $token)
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'blaxel' => ['https://abc.preview.bl.run/?bl_preview_token=bl-tok', 'https://abc.preview.bl.run', 'X-Blaxel-Preview-Token', 'bl-tok'],
    'runtime' => ['https://8081-abc.runtimehost.com/?runtime_preview_token=rt-tok', 'https://8081-abc.runtimehost.com', 'X-Runtime-Preview-Token', 'rt-tok'],
])->group('GW-002');

test('without the worker\'s secret nobody learns where a sandbox lives', function (string $secret) {
    config(['sandbox.gateway_secret' => 'worker-secret']);
    $this->sandbox->update(['preview_url' => 'https://abc.preview.bl.run/?bl_preview_token=bl-tok']);

    workerAuth("preview-{$this->sandbox->id}.onedrop.example.com", $this->owner, $secret)
        ->assertNotFound()
        ->assertHeaderMissing('X-OneDrop-Upstream-Token');
})->with(['wrong secret' => ['nope'], 'no secret' => ['']])->group('GW-002');

test('behind the worker, the address comes from the worker, not the forwarded host', function () {
    config(['sandbox.gateway_secret' => 'worker-secret']);
    $other = Sandbox::factory()->create(['preview_url' => 'https://other.preview.bl.run/?bl_preview_token=x']);

    // A browser can't pick another sandbox by sending its own forwarded host.
    test()->withHeaders([Gateway::SECRET_HEADER => 'worker-secret', Gateway::HOST_HEADER => "preview-{$this->sandbox->id}.onedrop.example.com", 'X-Forwarded-Host' => "preview-{$other->id}.onedrop.example.com"])
        ->withCookie(Gateway::COOKIE, app(Gateway::class)->pass($this->owner->id, ['kind' => 'preview', 'sandbox_id' => $this->sandbox->id]))
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'http://127.0.0.1:32800');
})->group('GW-002');

test('other users are refused behind the worker too', function () {
    config(['sandbox.gateway_secret' => 'worker-secret']);

    workerAuth("preview-{$this->sandbox->id}.onedrop.example.com")->assertUnauthorized();
    workerAuth("preview-{$this->sandbox->id}.onedrop.example.com", User::factory()->create())->assertForbidden();
})->group('GW-002');

test('the worker\'s hand-off sets the cookie for the address it names', function () {
    config(['sandbox.gateway_secret' => 'worker-secret']);
    $host = "preview-{$this->sandbox->id}.onedrop.example.com";
    parse_str((string) parse_url(app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner, '/contacts'), PHP_URL_QUERY), $query);
    $token = $query['token'];

    test()->withHeaders([Gateway::SECRET_HEADER => 'worker-secret', Gateway::HOST_HEADER => $host])
        ->get(route('sandbox-gateway.enter', ['token' => $token, 'path' => '/contacts']))
        ->assertRedirect("https://{$host}/contacts")
        ->assertCookie(Gateway::COOKIE);
})->group('GW-002');
