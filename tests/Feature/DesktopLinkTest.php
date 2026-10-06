<?php

use App\Enums\OrganizationRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TurnOutcome;
use App\Jobs\CreateSandbox;
use App\Jobs\MoveSandbox;
use App\Jobs\SyncSshKeys;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SshKey;
use App\Models\User;
use App\Sandbox\DesktopTunnel;
use App\Sandbox\ExecResult;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\DeviceSandboxProvider;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Providers\RoutingSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxUpdater;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);

    $this->owner = User::factory()->has(AgentConnection::factory())->create();
    $this->token = $this->owner->createToken('Ada’s MacBook', ['desktop']);
    $this->project = Project::factory()->for($this->owner)->create(['name' => 'Room bookings']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create([
        'status' => SandboxStatus::Running,
        'preview_url' => 'http://127.0.0.1:32800',
    ]);
});

/**
 * A call to the desktop app's API, with this computer's token.
 */
function desktopApi(string $method, string $url, array $data = [], ?string $token = null)
{
    // Each request finds its user from its own token, not the last request's.
    app('auth')->forgetGuards();

    return test()->withToken($token ?? test()->token->plainTextToken)->json($method, $url, $data);
}

test('the desktop app gets a ticket to forward a sandbox port, signed with the sandbox key', function () {
    $response = desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 5432])
        ->assertOk()
        ->assertJsonPath('device', null)
        ->assertJsonPath('hosts', null);

    $ticket = $response->json('ticket');

    expect(DesktopTunnel::verify(DesktopTunnel::key($this->sandbox), $ticket))
        ->toMatchArray(['p' => 'forward', 'port' => 5432, 'u' => $this->owner->id])
        ->and($response->json('url'))->toBe('ws://127.0.0.1:32800/__onedrop/tunnel?ticket='.$ticket)
        ->and($this->provider->executed)->toHaveCount(1)
        ->and($this->provider->executed[0]['command'])->toBe(['/opt/onedrop/tunnel', 'ensure'])
        ->and($this->provider->executed[0]['env'])->toBe(['ONEDROP_TUNNEL_KEY' => DesktopTunnel::key($this->sandbox)]);
})->group('DESK-007');

test('the tunnel goes through the preview gateway address when there is one', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);

    $url = desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 8081])
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith("wss://preview-{$this->sandbox->id}.onedrop.example.com/__onedrop/tunnel?ticket=");
})->group('DESK-007');

test('a ticket is refused for another sandbox, once it expires, or when tampered with', function () {
    $ticket = app(DesktopTunnel::class)->ticket($this->sandbox, $this->owner, 'forward', 5432);
    $other = Sandbox::factory()->for(Project::factory()->for($this->owner))->create();

    expect(DesktopTunnel::verify(DesktopTunnel::key($this->sandbox), $ticket))->not->toBeNull()
        ->and(DesktopTunnel::verify(DesktopTunnel::key($other), $ticket))->toBeNull()
        ->and(DesktopTunnel::verify(DesktopTunnel::key($this->sandbox), $ticket.'x'))->toBeNull();

    $this->travel(DesktopTunnel::TICKET_SECONDS + 1)->seconds();

    expect(DesktopTunnel::verify(DesktopTunnel::key($this->sandbox), $ticket))->toBeNull();
})->group('DESK-007');

test('the tunnel is started once, then trusted for a while', function () {
    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 5432])->assertOk();
    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 3306])->assertOk();

    expect($this->provider->executed)->toHaveCount(1);
})->group('DESK-007');

test('only people who can change the project get tickets, and not for the tunnel itself or a stopped sandbox', function () {
    $stranger = User::factory()->has(AgentConnection::factory())->create();

    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 5432], $stranger->createToken('Laptop')->plainTextToken)
        ->assertForbidden();

    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => config('sandbox.tunnel_port')])
        ->assertUnprocessable();

    $this->sandbox->update(['status' => SandboxStatus::Failed]);

    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 5432])
        ->assertConflict()
        ->assertJsonPath('message', "The project's sandbox isn't running.");
})->group('DESK-007');

test('a tunnel that will not start says so', function () {
    $this->provider->execUsing = fn () => new ExecResult(127, '', 'not found');

    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'forward', 'port' => 5432])
        ->assertServiceUnavailable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, "Couldn't start the sandbox's tunnel"));
})->group('DESK-007');

test('the gateway lets a tunnel with a valid ticket through without a cookie, and nothing else', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    $host = "preview-{$this->sandbox->id}.onedrop.example.com";
    $ticket = app(DesktopTunnel::class)->ticket($this->sandbox, $this->owner, 'forward', 5432);
    $authorize = fn (string $uri) => $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => $uri])->get(route('sandbox-gateway.authorize'));

    $authorize('/__onedrop/tunnel?ticket='.urlencode($ticket))->assertOk()->assertHeader('X-OneDrop-Upstream', '127.0.0.1:32800');
    $authorize('/__onedrop/tunnel?ticket=nope')->assertUnauthorized();
    $authorize('/somewhere?ticket='.urlencode($ticket))->assertUnauthorized();
    $authorize('/__onedrop/tunnel?dial=abc&token=def')->assertOk();
})->group('DESK-007', 'DESK-009');

test('only the owner gets an SSH ticket, after the sandbox has their keys', function () {
    $admin = User::factory()->has(AgentConnection::factory())->create();
    $this->project->organization->addMember($admin, OrganizationRole::Admin);

    desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'ssh'], $admin->createToken('Laptop')->plainTextToken)
        ->assertForbidden();

    $ticket = desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'ssh'])->assertOk()->json('ticket');

    expect(DesktopTunnel::verify(DesktopTunnel::key($this->sandbox), $ticket))->toMatchArray(['p' => 'forward', 'port' => (int) config('sandbox.ssh_port')])
        ->and(collect($this->provider->executed)->pluck('env')->contains(fn (array $env) => array_key_exists('APP_SSH_KEYS', $env)))->toBeTrue();
})->group('DESK-008');

test('the desktop app adds its own SSH key named after the computer, and replaces it', function () {
    Bus::fake([SyncSshKeys::class]);

    desktopApi('POST', route('api.desktop.ssh-key.store'), ['public_key' => sshKeyLine('first')])->assertOk();
    desktopApi('POST', route('api.desktop.ssh-key.store'), ['public_key' => sshKeyLine('second')])->assertOk();

    $keys = $this->owner->sshKeys()->get();

    expect($keys)->toHaveCount(1)
        ->and($keys[0]->name)->toBe('OneDrop desktop (Ada’s MacBook)')
        ->and($keys[0]->desktop_token_id)->toBe($this->token->accessToken->id)
        ->and($keys[0]->public_key)->toBe(sshKeyLine('second'));

    Bus::assertDispatched(SyncSshKeys::class);

    desktopApi('POST', route('api.desktop.ssh-key.store'), ['public_key' => 'not a key'])->assertUnprocessable();
})->group('DESK-008');

test("signing a computer out removes its SSH key from the owner's sandboxes", function () {
    Bus::fake([SyncSshKeys::class]);
    desktopApi('POST', route('api.desktop.ssh-key.store'), ['public_key' => sshKeyLine('laptop')])->assertOk();

    $this->actingAs($this->owner)->delete(route('desktop-devices.destroy', $this->token->accessToken->id))->assertRedirect();

    expect(SshKey::count())->toBe(0);
    Bus::assertDispatchedTimes(SyncSshKeys::class, 2);
})->group('DESK-008');

test('editors list the hosts on their network the project may reach', function () {
    $this->actingAs($this->owner)
        ->putJson(route('projects.computer.network', $this->project), ['hosts' => [
            ['host' => 'DB.internal', 'port' => 5432],
            ['host' => 'db.internal', 'port' => 5432],
            ['host' => '10.0.0.5', 'port' => 443],
        ]])
        ->assertOk()
        ->assertJsonPath('network_hosts', [['host' => 'db.internal', 'port' => 5432], ['host' => '10.0.0.5', 'port' => 443]]);

    $this->actingAs($this->owner)
        ->putJson(route('projects.computer.network', $this->project), ['hosts' => [['host' => 'https://intranet/path', 'port' => 443]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hosts.0.host');

    $ticket = desktopApi('POST', route('api.desktop.tunnel.store', $this->project), ['purpose' => 'network'])
        ->assertOk()
        ->assertJsonPath('hosts', [['host' => 'db.internal', 'port' => 5432], ['host' => '10.0.0.5', 'port' => 443]])
        ->json('ticket');

    expect(DesktopTunnel::verify(DesktopTunnel::key($this->sandbox), $ticket))->toMatchArray(['p' => 'network'])
        ->not->toHaveKey('port');

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->putJson(route('projects.computer.network', $this->project), ['hosts' => []])
        ->assertForbidden();
})->group('DESK-009');

test('the panel says what the project offers on this computer', function () {
    $this->provider->execUsing = fn () => new ExecResult(0, json_encode([
        ['address' => '0.0.0.0', 'port' => 8081, 'process' => 'node'],
        ['address' => '127.0.0.1', 'port' => 5432, 'process' => 'postgres'],
        ['address' => '127.0.0.1', 'port' => 7682, 'process' => 'node'],
        ['address' => '127.0.0.1', 'port' => 4000, 'process' => 'vite'],
    ]));

    $this->actingAs($this->owner)
        ->getJson(route('projects.computer.show', $this->project))
        ->assertOk()
        ->assertJsonPath('is_owner', true)
        ->assertJsonPath('host_alias', 'onedrop-room-bookings-'.$this->project->id)
        ->assertJsonPath('ports', [
            ['port' => 8081, 'label' => 'The app'],
            ['port' => 4000, 'label' => 'vite'],
            ['port' => 5432, 'label' => 'Postgres'],
        ])
        ->assertJsonPath('device', null)
        ->assertJsonPath('devices_available', false);
})->group('DESK-006', 'DESK-007');

test('only the owner moves a project, to the computer they ask from, and back', function () {
    Queue::fake();
    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'the-secret']);

    // A browser can't say which computer.
    $this->actingAs($this->owner)
        ->putJson(route('projects.computer.device', $this->project), ['to' => 'this'])
        ->assertUnprocessable();

    $admin = User::factory()->has(AgentConnection::factory())->create();
    $this->project->organization->addMember($admin, OrganizationRole::Admin);

    desktopApi('PUT', route('projects.computer.device', $this->project), ['to' => 'this'], $admin->createToken('Laptop')->plainTextToken)
        ->assertForbidden();

    desktopApi('PUT', route('projects.computer.device', $this->project), ['to' => 'this'])
        ->assertOk()
        ->assertJsonPath('moving', true);

    expect($this->project->fresh()->device_id)->toBe($this->token->accessToken->id)
        ->and($this->project->fresh()->sandboxProvider())->toBe('device');
    Queue::assertPushed(MoveSandbox::class, fn (MoveSandbox $job) => $job->from === null);

    $this->actingAs($this->owner)
        ->putJson(route('projects.computer.device', $this->project), ['to' => 'cloud'])
        ->assertOk();

    expect($this->project->fresh()->device_id)->toBeNull();
})->group('DESK-010');

test('installs without a Cloudflare gateway do not run projects on computers', function () {
    desktopApi('PUT', route('projects.computer.device', $this->project), ['to' => 'this'])->assertUnprocessable();

    desktopApi('POST', route('api.desktop.device.store'))
        ->assertNotFound()
        ->assertJsonPath('id', $this->token->accessToken->id)
        ->assertJsonPath('name', 'Ada’s MacBook');
})->group('DESK-010');

test('the desktop app gets a relay ticket for this computer, signed with the gateway secret', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'the-secret']);

    $response = desktopApi('POST', route('api.desktop.device.store'))
        ->assertOk()
        ->assertJsonPath('id', $this->token->accessToken->id)
        ->assertJsonPath('image', 'ghcr.io/onedrop-io/onedrop-sandbox:latest');

    parse_str((string) parse_url($response->json('url'), PHP_URL_QUERY), $query);

    expect($response->json('url'))->toStartWith("wss://relay.onedrop.example.com/__onedrop/devices/{$this->token->accessToken->id}/connect?")
        ->and(DesktopTunnel::verify('the-secret', $query['ticket']))->toMatchArray(['d' => $this->token->accessToken->id]);
})->group('DESK-010');

test('a move that fails leaves the project where it was and says why', function () {
    $updater = Mockery::mock(SandboxUpdater::class);
    $updater->shouldReceive('updateIfOutdated')->andThrow(new SandboxException('Waiting for Ada’s MacBook: open the OneDrop app there.'));
    $this->project->update(['device_id' => $this->token->accessToken->id]);

    (new MoveSandbox($this->project, null))->handle($updater);

    expect($this->project->fresh()->device_id)->toBeNull()
        ->and(MoveSandbox::error($this->project))->toBe('Waiting for Ada’s MacBook: open the OneDrop app there.');
})->group('DESK-010');

test('a project on a computer gets its sandboxes there, and the updater moves it', function () {
    $this->project->update(['device_id' => $this->token->accessToken->id]);

    expect(app(SandboxUpdater::class)->isOutdated($this->project->fresh()))->toBeTrue();

    $this->sandbox->delete();
    CreateSandbox::dispatchSync($this->project->fresh());

    expect($this->project->sandbox()->first()->provider)->toBe('device')
        ->and(collect($this->provider->created)->first()->deviceId)->toBe($this->token->accessToken->id);
})->group('DESK-010');

test('the routing provider creates sandboxes for a computer on the device provider', function () {
    $device = Mockery::mock(SandboxProvider::class);
    $device->shouldReceive('create')->once()->andReturn('4:onedrop-project-1-abc');
    $routing = new RoutingSandboxProvider(['docker' => fn () => new FakeSandboxProvider, 'device' => fn () => $device], 'docker');

    expect($routing->create(new SandboxSpec(name: 'onedrop-project-1-abc', deviceId: 4)))->toBe('4:onedrop-project-1-abc');
})->group('DESK-010');

test('the device provider calls the computer through the relay', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'the-secret']);
    Http::fake([
        'relay.onedrop.example.com/__onedrop/devices/4/rpc/create' => Http::response(['id' => 'onedrop-project-1-abc']),
        'relay.onedrop.example.com/__onedrop/devices/4/rpc/exec' => Http::response(['exit' => 0, 'stdout' => "hi\n", 'stderr' => '']),
    ]);
    $provider = devices();

    $id = $provider->create(new SandboxSpec(name: 'onedrop-project-1-abc', env: ['A' => 'b'], port: 8000, shellPort: 7681, proxyPort: 8081, sshPort: 2222, deviceId: 4));
    $result = $provider->exec($id, ['echo', 'hi'], ['X' => '1']);
    $provider->exec($id, ['rm', '-rf', '/tmp/x'], root: true);

    expect($id)->toBe('4:onedrop-project-1-abc')
        ->and($result->output)->toBe("hi\n")
        ->and($provider->previewUrl($id, 8081))->toBe('device:4/onedrop-project-1-abc:8081');

    Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('X-OneDrop-Gateway-Secret', 'the-secret')
        && str_ends_with($request->url(), '/rpc/create')
        && $request['image'] === 'ghcr.io/onedrop-io/onedrop-sandbox:latest'
        && $request['ports'] === ['app' => 8000, 'proxy' => 8081, 'shell' => 7681, 'ssh' => 2222]);
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/rpc/exec')
        && $request['id'] === 'onedrop-project-1-abc' && $request['command'] === ['echo', 'hi'] && $request['detach'] === false && ! isset($request['user']));
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/rpc/exec')
        && $request['command'] === ['rm', '-rf', '/tmp/x'] && $request['user'] === 'root');
})->group('DESK-010');

test('the device provider says which computer to open when it is offline', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'the-secret']);
    Http::fake(['*' => Http::response(['error' => 'offline'], 503)]);

    expect(fn () => devices()->exec("{$this->token->accessToken->id}:onedrop-project-1-abc", ['true']))
        ->toThrow(SandboxException::class, 'Waiting for Ada’s MacBook: open the OneDrop app there.');
})->group('DESK-010');

test('the device provider copies files in and out as tar streams', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'the-secret']);
    $source = sys_get_temp_dir().'/onedrop-device-test-'.uniqid();
    mkdir($source);
    file_put_contents("{$source}/hello.txt", 'hello');
    $archive = tempnam(sys_get_temp_dir(), 'tar');
    exec('tar -cf '.escapeshellarg($archive).' -C '.escapeshellarg($source).' .');
    Http::fake([
        '*/rpc/copy-out*' => Http::response(file_get_contents($archive)),
        '*/rpc/copy-in*' => Http::response([]),
    ]);
    $target = sys_get_temp_dir().'/onedrop-device-out-'.uniqid();
    mkdir($target);

    devices()->copyOut('4:onedrop-project-1-abc', '/workspace', $target);
    devices()->installFiles('4:onedrop-project-1-abc', $source, '/opt/onedrop');

    expect(file_get_contents("{$target}/hello.txt"))->toBe('hello');
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/rpc/copy-in?')
        && str_contains($request->url(), 'root=1')
        && str_contains($request->url(), 'path=%2Fopt%2Fonedrop')
        && $request->hasHeader('Content-Type', 'application/x-tar'));
})->group('DESK-010');

test("the gateway hands a computer's preview and Shell to its relay", function () {
    $this->sandbox->update([
        'provider' => 'device',
        'external_id' => '4:onedrop-project-1-abc',
        'preview_url' => 'device:4/onedrop-project-1-abc:8081',
        'shell_url' => 'device:4/onedrop-project-1-abc:7681',
    ]);

    expect(app(Gateway::class)->target($this->sandbox->fresh(), 'shell'))
        ->toBe(['url' => 'device:4/onedrop-project-1-abc:7681', 'header' => null, 'token' => null]);

    config(['sandbox.gateway_domain' => 'onedrop.example.com', 'sandbox.gateway_secret' => 'the-secret']);
    $this->sandbox->update(['external_id' => "{$this->token->accessToken->id}:onedrop-project-1-abc"]);

    $this->withHeaders([
        Gateway::SECRET_HEADER => 'the-secret',
        Gateway::HOST_HEADER => "preview-{$this->sandbox->id}.onedrop.example.com",
    ])->withCookie(Gateway::COOKIE, app(Gateway::class)->pass($this->owner->id, ['kind' => 'preview', 'sandbox_id' => $this->sandbox->id]))
        ->get(route('sandbox-gateway.authorize'))
        ->assertOk()
        ->assertHeader('X-OneDrop-Upstream', 'device:4/onedrop-project-1-abc:8081')
        ->assertHeader('X-OneDrop-Upstream-Computer', rawurlencode('Ada’s MacBook'));
})->group('DESK-010');

test("the menu bar icon lists the user's projects that are working or waiting", function () {
    $this->project->update(['status' => ProjectStatus::Working]);
    Project::factory()->for($this->owner)->create(['name' => 'Waiting one', 'turn_outcome' => TurnOutcome::Question]);
    Project::factory()->for($this->owner)->create(['name' => 'Done one']);
    Project::factory()->create(['status' => ProjectStatus::Working]);

    desktopApi('GET', route('api.desktop.activity'))
        ->assertOk()
        ->assertJsonCount(2, 'projects')
        ->assertJsonFragment(['name' => 'Room bookings', 'working' => true, 'waiting_for' => null])
        ->assertJsonFragment(['name' => 'Waiting one', 'working' => false, 'waiting_for' => 'question']);
})->group('DESK-011');

test('projects offer "Open in the desktop app" once it is signed in somewhere', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('desktopAppSignedIn', false));

    $this->actingAs($this->owner)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('desktopAppSignedIn', true));
})->group('DESK-011');

/**
 * The device provider as the app makes it.
 */
function devices(): DeviceSandboxProvider
{
    return new DeviceSandboxProvider([
        ...config('sandbox.providers.device'),
        'gateway_domain' => config('sandbox.gateway_domain'),
        'gateway_secret' => config('sandbox.gateway_secret'),
    ]);
}

/**
 * A made-up ed25519 public key line (the key data names its type, as SshKey::parse() checks).
 */
function sshKeyLine(string $seed): string
{
    $type = 'ssh-ed25519';
    $blob = pack('N', strlen($type)).$type.pack('N', 32).substr(hash('sha256', $seed, true), 0, 32);

    return "{$type} ".base64_encode($blob);
}
