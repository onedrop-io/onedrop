<?php

use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\AnnounceSandboxCallback;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxCallback;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['sandbox.callback_url' => 'http://host.docker.internal:8000/']);
});

test('tells public addresses from ones only this machine can reach', function (string $url, bool $public) {
    expect(SandboxCallback::isPublic($url))->toBe($public);
})->with([
    ['http://host.docker.internal:8000', false],
    ['http://localhost:8000', false],
    ['http://127.0.0.1:8000', false],
    ['http://192.168.1.20:8000', false],
    ['http://zap.test', false],
    ['http://web:8000', false],
    ['https://onedrop.example.com', true],
    ['https://abc-def.trycloudflare.com', true],
    ['http://8.8.8.8', true],
])->group('SBX-015');

test('sandboxes on this machine use the configured address', function () {
    expect(app(SandboxCallback::class)->reachableUrl('docker'))->toBe('http://host.docker.internal:8000');
})->group('SBX-015');

test('cloud sandboxes use the dev tunnel while it runs, and have no address without it', function () {
    $callback = app(SandboxCallback::class);

    expect($callback->reachableUrl('e2b'))->toBeNull()
        ->and($callback->problem('e2b'))->toContain("can't reach OneDrop at http://host.docker.internal:8000");

    Cache::forever(SandboxCallback::TUNNEL_CACHE_KEY, 'https://abc-def.trycloudflare.com');

    expect($callback->reachableUrl('e2b'))->toBe('https://abc-def.trycloudflare.com')
        ->and($callback->problem('e2b'))->toBeNull()
        ->and($callback->reachableUrl('docker'))->toBe('http://host.docker.internal:8000');
})->group('SBX-015');

test('cloud sandboxes use a public configured address as it is', function () {
    config(['sandbox.callback_url' => 'https://onedrop.example.com']);
    Cache::forever(SandboxCallback::TUNNEL_CACHE_KEY, 'https://abc-def.trycloudflare.com');

    expect(app(SandboxCallback::class)->reachableUrl('runtime'))->toBe('https://onedrop.example.com');
})->group('SBX-015');

describe('agent runs', function () {
    beforeEach(function () {
        $this->provider = new class extends FakeSandboxProvider
        {
            /** @var list<array<string, string>> */
            public array $envs = [];

            public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
            {
                if ($command === ['node', '/opt/onedrop/forwarder.mjs']) {
                    $this->envs[] = $env;
                }

                return parent::exec($id, $command, $env, $detach, $root);
            }
        };
        app()->instance(SandboxProvider::class, $this->provider);

        $user = User::factory()->has(AgentConnection::factory()->provider(AgentProvider::OpenRouter))->create();
        $this->project = Project::factory()->for($user)->create(['status' => ProjectStatus::Working]);
        $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1', 'provider' => 'e2b']);
        $this->message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);
    });

    test('a cloud sandbox that cannot reach the app explains instead of thinking forever', function () {
        app(OpenCodeRunner::class)->start($this->project, $this->message);

        expect($this->provider->executed)->toBe([])
            ->and($this->project->messages()->reorder()->latest('id')->first()->content)->toContain('composer run dev')
            ->and($this->project->fresh()->status)->toBe(ProjectStatus::Idle);
    });

    test('a cloud sandbox reports its events through the dev tunnel', function () {
        Cache::forever(SandboxCallback::TUNNEL_CACHE_KEY, 'https://abc-def.trycloudflare.com');

        app(OpenCodeRunner::class)->start($this->project, $this->message);

        expect($this->provider->envs[0]['APP_EVENTS_URL'])->toBe("https://abc-def.trycloudflare.com/sandbox-events/{$this->sandbox->id}")
            ->and(collect($this->provider->executed)->pluck('command')->first(fn (array $command) => str_contains($command[2] ?? '', '/etc/onedrop-callback')))->toContain('https://abc-def.trycloudflare.com');
    });
})->group('SBX-015');

test('the tunnel serves only what sandboxes call', function () {
    $sandbox = Sandbox::factory()->create();

    $this->get('http://abc-def.trycloudflare.com/login')->assertNotFound();
    $this->get('http://abc-def.trycloudflare.com/')->assertNotFound();
    $this->postJson("http://abc-def.trycloudflare.com/sandbox-events/{$sandbox->id}", ['events' => [[]]])->assertUnauthorized();
    $this->get('http://localhost/login')->assertOk();
})->group('SBX-015');

test('the tunnel command is not needed when the configured address is public', function () {
    config(['sandbox.callback_url' => 'https://onedrop.example.com']);

    $this->artisan('sandbox:tunnel')
        ->expectsOutputToContain('already public')
        ->assertSuccessful();
})->group('SBX-015');

describe('a new tunnel address', function () {
    beforeEach(function () {
        $this->provider = new FakeSandboxProvider;
        app()->instance(SandboxProvider::class, $this->provider);
        Cache::forever(SandboxCallback::TUNNEL_CACHE_KEY, 'https://new-tunnel.trycloudflare.com');
    });

    test('reaches every awake cloud sandbox, so running agents keep reporting back', function () {
        $awake = Sandbox::factory()->create(['provider' => 'e2b', 'external_id' => 'sbx-awake']);
        Sandbox::factory()->create(['provider' => 'e2b', 'external_id' => 'sbx-asleep', 'suspended_at' => now()]);
        Sandbox::factory()->create(['provider' => 'docker', 'external_id' => 'ctr-1']);

        AnnounceSandboxCallback::dispatchSync();

        expect($this->provider->executed)->toHaveCount(1)
            ->and($this->provider->executed[0]['id'])->toBe($awake->external_id)
            ->and($this->provider->executed[0]['command'])->toBe(['sh', '-c', 'printf %s "$1" > /etc/onedrop-callback', 'sh', 'https://new-tunnel.trycloudflare.com'])
            ->and($this->provider->executed[0]['root'])->toBeTrue();
    });

    test('is not written anywhere when the configured address is public', function () {
        config(['sandbox.callback_url' => 'https://onedrop.example.com']);
        Sandbox::factory()->create(['provider' => 'e2b', 'external_id' => 'sbx-awake']);

        AnnounceSandboxCallback::dispatchSync();

        expect($this->provider->executed)->toBe([]);
    });
})->group('SBX-015');
