<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SshKey;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['sandbox.gateway_domain' => null]);

    // Answers the in-sandbox scripts by what they read; the SSH key sync succeeds.
    $this->outputs = [
        'ports' => json_encode([
            ['address' => '0.0.0.0', 'port' => 8081, 'pid' => 12, 'process' => 'node'],
            ['address' => '0.0.0.0', 'port' => 8000, 'pid' => 40, 'process' => 'php'],
            ['address' => '::', 'port' => 5173, 'pid' => 41, 'process' => 'node'],
            ['address' => '100.64.0.1', 'port' => 443, 'pid' => null, 'process' => null],
        ]),
        'usage' => json_encode(['cpus' => 2, 'cpu' => 0.123456, 'user' => 0.1, 'system' => 0.023456, 'memory_limit' => 2147483648, 'memory' => 536870912, 'active' => 400000000, 'cache' => 100000000]),
        'storage' => json_encode(['workspace' => 700000000, 'dependencies' => 600000000, 'storage' => 0, 'disk_total' => 64000000000, 'disk_free' => 32000000000]),
        'sync' => 0,
    ];
    $this->provider = new FakeSandboxProvider;
    $this->provider->execUsing = fn (array $command) => match (true) {
        str_contains($command[2], '/proc/net/tcp') => new ExecResult(0, $this->outputs['ports']),
        str_contains($command[2], 'cpu.stat') => new ExecResult(0, $this->outputs['usage']),
        str_contains($command[2], 'disk_total_space') => new ExecResult(0, $this->outputs['storage']),
        default => new ExecResult($this->outputs['sync'], ''),
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Ada']);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Todo App']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create([
        'external_id' => 'ctr-1',
        'preview_url' => 'http://127.0.0.1:55001',
        'ssh_address' => '127.0.0.1:55022',
    ]);
});

test('networking lists the ports in the sandbox, lowest first, with what uses each', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.developer.networking', $this->project))
        ->assertOk()
        ->assertJsonPath('app_port', 8000)
        ->assertJsonPath('ports', [
            ['address' => '100.64.0.1', 'port' => 443, 'pid' => null, 'process' => null, 'role' => null],
            ['address' => '::', 'port' => 5173, 'pid' => 41, 'process' => 'node', 'role' => null],
            ['address' => '0.0.0.0', 'port' => 8000, 'pid' => 40, 'process' => 'php', 'role' => 'app'],
            ['address' => '0.0.0.0', 'port' => 8081, 'pid' => 12, 'process' => 'node', 'role' => 'proxy'],
        ]);
})->group('DEVTOOLS-001');

test('a preview on this machine gets no QR code; a published address does', function () {
    $this->project->update([
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Public,
        'published_url' => 'https://todo-app-1.tail123.ts.net',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.developer.networking', $this->project))
        ->assertOk()
        ->assertJsonPath('preview.url', 'http://127.0.0.1:55001')
        ->assertJsonPath('preview.local', true)
        ->assertJsonPath('preview.qr', null)
        ->assertJsonPath('published.url', 'https://todo-app-1.tail123.ts.net')
        ->assertJsonPath('published.visibility', 'public');

    expect($response->json('published.qr'))->toStartWith('<svg');
})->group('DEVTOOLS-001');

test('on a server the preview is the gateway address, with a QR code', function () {
    config(['sandbox.gateway_domain' => 'onedrop.example.com']);
    URL::forceRootUrl('https://onedrop.example.com');

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.developer.networking', $this->project))
        ->assertOk()
        ->assertJsonPath('preview.url', route('projects.gateway.open', [$this->project, 'preview']))
        ->assertJsonPath('preview.local', false)
        ->assertJsonPath('published', null);

    expect($response->json('preview.qr'))->toStartWith('<svg');
})->group('DEVTOOLS-001');

test('resources report CPU and memory against the limits, and disk use', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.developer.usage', $this->project))
        ->assertOk()
        ->assertExactJson(['usage' => [
            'cpus' => 2, 'cpu' => 0.1235, 'user' => 0.1, 'system' => 0.0235,
            'memory_limit' => 2147483648, 'memory' => 536870912, 'active' => 400000000, 'cache' => 100000000,
        ]]);

    $this->actingAs($this->user)
        ->getJson(route('projects.developer.storage', $this->project))
        ->assertOk()
        ->assertExactJson(['storage' => [
            'workspace' => 700000000, 'dependencies' => 600000000, 'storage' => 0, 'disk_total' => 64000000000, 'disk_free' => 32000000000,
        ]]);
})->group('DEVTOOLS-001');

test('missing measurements come back as null', function () {
    $this->outputs['usage'] = json_encode(['cpus' => 2, 'cpu' => null, 'memory' => 'lots']);

    $this->actingAs($this->user)
        ->getJson(route('projects.developer.usage', $this->project))
        ->assertOk()
        ->assertJsonPath('usage.cpus', 2)
        ->assertJsonPath('usage.cpu', null)
        ->assertJsonPath('usage.memory', null)
        ->assertJsonPath('usage.cache', null);
})->group('DEVTOOLS-001');

test('a sandbox that can\'t be read gives a clear error', function () {
    $this->outputs['ports'] = 'PHP Fatal error';

    $this->actingAs($this->user)
        ->getJson(route('projects.developer.networking', $this->project))
        ->assertStatus(502)
        ->assertJsonPath('message', "Couldn't read the sandbox's details.");
})->group('DEVTOOLS-001');

test('developer tools need a running sandbox', function (string $route) {
    $this->sandbox->update(['status' => SandboxStatus::Paused]);

    $this->actingAs($this->user)
        ->getJson(route($route, $this->project))
        ->assertConflict()
        ->assertJsonPath('message', "The project's sandbox isn't running.");
})->with(['projects.developer.networking', 'projects.developer.usage', 'projects.developer.storage', 'projects.developer.ssh'])->group('DEVTOOLS-001');

test('other users can\'t see a project\'s developer tools', function (string $route) {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->getJson(route($route, $this->project))
        ->assertForbidden();

    expect($this->provider->executed)->toBe([]);
})->with(['projects.developer.networking', 'projects.developer.usage', 'projects.developer.storage', 'projects.developer.ssh'])->group('DEVTOOLS-001');

test('ssh gives the connection details and puts the owner\'s keys in the sandbox', function () {
    SshKey::factory()->for($this->user)->create(['name' => 'Laptop', 'public_key' => 'ssh-ed25519 AAAAOWNER']);

    $this->actingAs($this->user)
        ->getJson(route('projects.developer.ssh', $this->project))
        ->assertOk()
        ->assertExactJson([
            'unavailable' => null,
            'host_alias' => 'onedrop-todo-app-'.$this->project->id,
            'host' => '127.0.0.1',
            'port' => 55022,
            'user' => 'sandbox',
            'path' => '/workspace',
            'owner_keys' => 1,
            'is_owner' => true,
            'owner_name' => 'Ada',
        ]);

    expect($this->provider->executed[0]['env'])->toBe(['APP_SSH_KEYS' => 'ssh-ed25519 AAAAOWNER Laptop']);
})->group('DEVTOOLS-001');

test('an admin connects with the owner\'s keys, not their own', function () {
    $admin = User::factory()->admin()->has(AgentConnection::factory())->create();
    SshKey::factory()->for($admin)->create(['public_key' => 'ssh-ed25519 AAAAADMIN']);

    $this->actingAs($admin)
        ->getJson(route('projects.developer.ssh', $this->project))
        ->assertOk()
        ->assertJsonPath('is_owner', false)
        ->assertJsonPath('owner_keys', 0);

    expect($this->provider->executed[0]['env']['APP_SSH_KEYS'])->toBe('');
})->group('DEVTOOLS-001');

test('ssh explains when it isn\'t available', function (Closure $setUp, string $message) {
    $setUp->call($this);

    $this->actingAs($this->user)
        ->getJson(route('projects.developer.ssh', $this->project))
        ->assertOk()
        ->assertJsonPath('unavailable', fn (string $text) => str_contains($text, $message));
})->with([
    'on a server' => [fn () => config(['sandbox.gateway_domain' => 'onedrop.example.com']), "isn't available on this server"],
    'sandbox without an ssh port' => [fn () => $this->sandbox->update(['ssh_address' => null]), 'created before SSH was added'],
    'image without sshd' => [fn () => $this->outputs['sync'] = WorkspaceSsh::NO_SERVER, "image doesn't have SSH yet"],
])->group('DEVTOOLS-001');

test('a new sandbox publishes an ssh port, records where it is, and gets the owner\'s keys', function () {
    $provider = new class extends FakeSandboxProvider
    {
        public function previewUrl(string $id, int $port): ?string
        {
            return "http://127.0.0.1:1{$port}";
        }
    };
    app()->instance(SandboxProvider::class, $provider);
    SshKey::factory()->for($this->user)->create(['name' => 'Laptop', 'public_key' => 'ssh-ed25519 AAAAOWNER']);

    CreateSandbox::dispatchSync($this->project);

    expect(collect($provider->created)->sole()->sshPort)->toBe(2222)
        ->and($this->project->sandbox->fresh()->ssh_address)->toBe('127.0.0.1:12222')
        ->and(collect($provider->executed)->last()['env'])->toBe(['APP_SSH_KEYS' => 'ssh-ed25519 AAAAOWNER Laptop']);
})->group('DEVTOOLS-001');
