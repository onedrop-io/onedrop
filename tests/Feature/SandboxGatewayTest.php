<?php

use App\Enums\SandboxStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Gateway;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['sandbox.gateway_domain' => 'zap.example.com']);

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
    gatewayAuth("{$kind}-{$this->sandbox->id}.zap.example.com", $this->owner)
        ->assertOk()
        ->assertHeader('X-Zap-Upstream', $upstream);
})->with([
    'preview' => ['preview', '127.0.0.1:32800'],
    'shell' => ['shell', '127.0.0.1:32799'],
])->group('GW-001');

test('the app login cookie alone does not open a preview', function () {
    $this->actingAs($this->owner);

    gatewayAuth("shell-{$this->sandbox->id}.zap.example.com")
        ->assertUnauthorized()
        ->assertHeaderMissing('X-Zap-Upstream')
        ->assertHeader('X-Zap-Gateway', 'login-required; reason=no-cookie')
        ->assertSee('Open this preview from the app builder')
        ->assertSee(rtrim(config('app.url'), '/')."/projects/{$this->project->id}/open/shell", escape: false);
})->group('GW-001');

test('an expired or foreign pass is refused', function (Closure $pass) {
    gatewayAuth("preview-{$this->sandbox->id}.zap.example.com", pass: $pass($this))
        ->assertUnauthorized()
        ->assertHeader('X-Zap-Gateway', 'login-required; reason=expired')
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
    gatewayAuth("shell-{$this->sandbox->id}.zap.example.com", User::factory()->create())
        ->assertForbidden()
        ->assertHeaderMissing('X-Zap-Upstream');
})->group('GW-001');

test('admins can open any sandbox', function () {
    gatewayAuth("preview-{$this->sandbox->id}.zap.example.com", User::factory()->admin()->create())->assertOk();
})->group('GW-001');

test('unknown hosts, sandboxes and stopped sandboxes are not found', function (Closure $host) {
    gatewayAuth($host($this->sandbox), $this->owner)->assertNotFound();
})->with([
    'wrong domain' => [fn ($sandbox) => "preview-{$sandbox->id}.evil.com"],
    'unknown kind' => [fn ($sandbox) => "db-{$sandbox->id}.zap.example.com"],
    'missing sandbox' => [fn () => 'preview-999999.zap.example.com'],
    'stopped sandbox' => [function ($sandbox) {
        $sandbox->update(['status' => SandboxStatus::Paused]);

        return "preview-{$sandbox->id}.zap.example.com";
    }],
])->group('GW-001');

test('opening a preview hands the browser to that address, which sets its own cookie', function () {
    $open = $this->actingAs($this->owner)->get(route('projects.gateway.open', [$this->project, 'preview', 'path' => '/contacts?page=2']));
    $enterUrl = $open->assertRedirect()->headers->get('Location');

    expect($enterUrl)->toStartWith("https://preview-{$this->sandbox->id}.zap.example.com/__zap/enter?");

    // A fresh browser on the preview address: no app session involved.
    auth()->logout();
    $enter = $this->get($enterUrl);

    $enter->assertRedirect("https://preview-{$this->sandbox->id}.zap.example.com/contacts?page=2")->assertCookie(Gateway::COOKIE)->assertCookieMissing(config('session.cookie'));
    $cookie = collect($enter->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Gateway::COOKIE);
    expect($cookie->getDomain())->toBeNull()->and($cookie->isHttpOnly())->toBeTrue();

    // The browser sends back exactly what the address set.
    $this->withUnencryptedCookie(Gateway::COOKIE, $cookie->getValue())
        ->withHeaders(['X-Forwarded-Host' => "preview-{$this->sandbox->id}.zap.example.com"])
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk();
})->group('GW-001');

test('hand-off tokens only work briefly, on their own address', function () {
    $url = app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner);
    $token = parse_url($url, PHP_URL_QUERY);

    $this->get("https://shell-{$this->sandbox->id}.zap.example.com/__zap/enter?{$token}")->assertUnauthorized();
    $this->get("https://zap.example.com/__zap/enter?{$token}")->assertUnauthorized();

    $this->travel(Gateway::TOKEN_SECONDS + 1)->seconds();
    $this->get($url)->assertUnauthorized()->assertCookieMissing(Gateway::COOKIE);
})->group('GW-001');

test('the hand-off only redirects within the address', function (string $path) {
    $url = app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner, $path);

    $this->get($url)->assertRedirect("https://preview-{$this->sandbox->id}.zap.example.com/");
})->with(['//evil.com', 'https://evil.com', '/\\evil.com'])->group('GW-001');

test('behind the internal plain-http hop, the hand-off still lands on https', function () {
    $url = str_replace('https://', 'http://', app(Gateway::class)->enterUrl($this->sandbox, 'preview', $this->owner));

    $enter = $this->get($url)->assertRedirect("https://preview-{$this->sandbox->id}.zap.example.com/");

    expect(collect($enter->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Gateway::COOKIE)->isSecure())->toBeTrue();
})->group('GW-001');

test('the reopen link points at the app, not the preview host', function () {
    config(['app.url' => 'https://zap.example.com']);

    $this->withHeaders(['Host' => "preview-{$this->sandbox->id}.zap.example.com"])
        ->get("http://preview-{$this->sandbox->id}.zap.example.com/__zap/enter?token=bad")
        ->assertUnauthorized()
        ->assertSee("https://zap.example.com/projects/{$this->project->id}/open/preview", escape: false);
})->group('GW-001');

test('only people who can see the project can open it', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->get(route('projects.gateway.open', [$this->project, 'preview']))
        ->assertForbidden();

    auth()->logout();
    $this->get(route('projects.gateway.open', [$this->project, 'preview']))->assertRedirect(route('login'));
})->group('GW-001');

test('certificates are only issued for existing sandbox hosts', function () {
    $this->get(route('sandbox-gateway.certificate', ['domain' => "preview-{$this->sandbox->id}.zap.example.com"]))->assertOk();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'preview-999999.zap.example.com']))->assertNotFound();
    $this->get(route('sandbox-gateway.certificate', ['domain' => 'anything.zap.example.com']))->assertNotFound();
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

    gatewayAuth("preview-{$this->sandbox->id}.zap.example.com", $this->owner)->assertNotFound();
    $this->actingAs($this->owner)->get(route('projects.gateway.open', [$this->project, 'preview']))->assertNotFound();
})->group('GW-001');
