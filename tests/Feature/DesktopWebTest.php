<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\UseDesktopToken;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Gateway;
use Inertia\Support\Header;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->token = $this->user->createToken('Laptop')->plainTextToken;
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Vacation calendar']);
});

/**
 * The desktop app's Inertia visit with its token, as its HTTP client makes it.
 */
function desktopVisit(string $token, string $method, string $url, array $data = [], array $headers = [])
{
    $version = app(HandleInertiaRequests::class)->version(request()) ?? '';

    return test()->withToken($token)
        ->withHeaders([Header::INERTIA => 'true', Header::VERSION => $version, ...$headers])
        ->json($method, $url, $data);
}

test('the desktop app opens the web app\'s own pages with its token', function () {
    desktopVisit($this->token, 'GET', route('projects.show', $this->project))
        ->assertOk()
        ->assertHeader(Header::INERTIA)
        ->assertJsonPath('component', 'projects/show')
        ->assertJsonPath('props.project.name', 'Vacation calendar')
        ->assertJsonPath('props.auth.user.email', $this->user->email);

    expect(PersonalAccessToken::findToken($this->token)->last_used_at)->not->toBeNull();
})->group('DESK-002');

test('without a valid token the app is a guest, sent to the sign-in page', function (?string $token) {
    $this->withHeaders($token ? ['Authorization' => "Bearer {$token}"] : [])
        ->get(route('projects.show', $this->project))
        ->assertRedirect(route('login'));
})->with([
    'no token' => [null],
    'an unknown token' => ['1|onedrop_nope'],
])->group('DESK-001');

test('a token signed out of the app no longer opens pages', function () {
    $this->user->tokens()->delete();

    $this->withToken($this->token)->get(route('projects.show', $this->project))->assertRedirect(route('login'));
})->group('DESK-001');

test('the app keeps a session of its own, so what the web flashes after a redirect reaches it', function () {
    $other = Project::factory()->for($this->user)->create(['name' => 'Old tracker']);

    desktopVisit($this->token, 'DELETE', route('projects.destroy', $other))->assertRedirect();

    desktopVisit($this->token, 'GET', route('projects.show', $this->project))
        ->assertJsonPath('flash.toast.message', 'Deleted “Old tracker”.');
})->group('DESK-002');

test('each token has its own session, which nobody can name without the app\'s key', function () {
    $laptop = PersonalAccessToken::findToken($this->token);
    $desktop = PersonalAccessToken::findToken($this->user->createToken('Desktop')->plainTextToken);

    expect(UseDesktopToken::sessionId($laptop))->toHaveLength(40)
        ->not->toBe(UseDesktopToken::sessionId($desktop))
        ->and(ctype_alnum(UseDesktopToken::sessionId($laptop)))->toBeTrue();
})->group('DESK-001');

test('the web app\'s UI cookies come from the app in a header', function () {
    $other = Project::factory()->for($this->user)->create(['name' => 'Opened one']);

    desktopVisit($this->token, 'GET', route('projects.show', $this->project), headers: [
        'X-Onedrop-Cookies' => "open_project={$other->id}; sidebar_state=false; unrelated=1",
    ])
        ->assertJsonPath('props.openProject.name', 'Opened one')
        ->assertJsonPath('props.sidebarOpen', false);
})->group('DESK-002');

test('on a server the app opens previews through the sandbox\'s own sign-in address', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    $sandbox = Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:41000']);

    $url = desktopVisit($this->token, 'GET', route('projects.show', $this->project))->json('props.sandbox.preview_url');

    expect($url)->toStartWith("https://preview-{$sandbox->id}.onedrop.example.com/__onedrop/enter?");
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(app(Gateway::class)->userFromToken($query['token'], ['kind' => 'preview', 'sandbox_id' => $sandbox->id]))->toBe($this->user->id);

    // The browser keeps going through the app's own address, which its session signs in.
    $this->flushHeaders()->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('sandbox.preview_url', route('projects.gateway.open', [$this->project, 'preview'])));
})->group('DESK-002');

test('the app connects for live updates to the server it signed in to', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'app-key',
        'broadcasting.connections.reverb.browser' => ['host' => null, 'port' => 443, 'scheme' => 'https'],
    ]);

    desktopVisit($this->token, 'GET', 'http://onedrop.example.com'.route('projects.show', $this->project, false))
        ->assertJsonPath('props.realtime.host', 'onedrop.example.com');

    // A browser connects to the page's own address.
    $this->flushHeaders()->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('realtime.host', null));
})->group('DESK-002');

test('only the desktop app\'s origins may call the server from another origin, and read Inertia\'s headers', function () {
    $this->withHeaders(['Origin' => 'tauri://localhost', 'Access-Control-Request-Method' => 'POST'])
        ->options(route('projects.show', $this->project))
        ->assertHeader('Access-Control-Allow-Origin', 'tauri://localhost');

    $this->withToken($this->token)->withHeaders(['Origin' => 'http://tauri.localhost'])
        ->get(route('projects.show', $this->project))
        ->assertHeader('Access-Control-Allow-Origin', 'http://tauri.localhost')
        ->assertHeader('Access-Control-Expose-Headers');

    // The development app signs in to any server, onedrop.io included.
    $this->withHeaders(['Origin' => 'http://localhost:1420', 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/desktop/token')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:1420');

    $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])
        ->options(route('projects.show', $this->project))
        ->assertHeaderMissing('Access-Control-Allow-Origin');
})->group('DESK-001');

test('the app is told when the server\'s release was made, and browsers aren\'t', function () {
    config(['app.released_at' => 1791072000]);

    desktopVisit($this->token, 'GET', route('projects.show', $this->project))
        ->assertHeader('X-Onedrop-Released', '1791072000');

    $this->flushHeaders()->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertHeaderMissing('X-Onedrop-Released');

    // A server built from source has no release.
    config(['app.released_at' => null]);

    desktopVisit($this->token, 'GET', route('projects.show', $this->project))
        ->assertHeaderMissing('X-Onedrop-Released');
})->group('DESK-004');
