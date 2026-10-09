<?php

use App\Models\Sandbox;
use App\Sandbox\Gateway;
use App\Sandbox\Providers\E2bSandboxProvider;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxWaitLimit;
use App\Sandbox\TemporarySandboxException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

const E2B_API = 'https://api.e2b.app';
const E2B_ID = 'i3orqfjc7ynxe7bus57zo';
const E2B_ENVD = 'https://49983-'.E2B_ID.'.e2b.app';

beforeEach(function () {
    Http::preventStrayRequests();

    $this->e2b = new E2bSandboxProvider([
        'api_key' => 'e2b-test-key',
        'url' => E2B_API,
        'domain' => 'e2b.app',
        'image' => 'onedrop-sandbox',
        'idle_seconds' => 600,
        'nested_docker' => 'on',
    ]);
});

/**
 * envd's answer to a process start: Connect frames (a flags byte, a big-endian length, JSON), then the end of stream.
 *
 * @param  list<array<string, mixed>>  $events
 */
function envdStream(array $events, ?array $error = null): string
{
    $frames = '';

    foreach ($events as $event) {
        $json = json_encode(['event' => $event]);
        $frames .= pack('CN', 0, strlen($json)).$json;
    }

    $end = json_encode($error ? ['error' => $error] : (object) []);

    return $frames.pack('CN', 2, strlen($end)).$end;
}

function envdRan(string $stdout = '', string $stderr = '', int $exitCode = 0): string
{
    return envdStream([
        ['start' => ['pid' => 42]],
        ...($stdout !== '' ? [['data' => ['stdout' => base64_encode($stdout)]]] : []),
        ...($stderr !== '' ? [['data' => ['stderr' => base64_encode($stderr)]]] : []),
        // Protobuf's JSON leaves a zero exit code out.
        ['end' => array_filter(['exitCode' => $exitCode, 'exited' => true])],
    ]);
}

function e2bTemplates(string $status = 'ready', string $build = 'build-7'): array
{
    return [['templateID' => 'tpl1', 'buildID' => $build, 'aliases' => ['onedrop-sandbox'], 'buildStatus' => $status]];
}

function e2bConnected(): array
{
    return ['sandboxID' => E2B_ID, 'envdAccessToken' => 'envd-secret', 'trafficAccessToken' => 'traffic-secret'];
}

/**
 * The envd request's process, decoded from its Connect frame.
 *
 * @return array<string, mixed>
 */
function envdProcess(Request $request): array
{
    return json_decode(substr($request->body(), 5), true)['process'];
}

test('create starts a private, auto-pausing sandbox from the current template build and hands it its settings in a private file', function () {
    Http::fake([
        E2B_API.'/templates' => Http::response(e2bTemplates()),
        E2B_API.'/v2/sandboxes' => Http::response(e2bConnected()),
        E2B_ENVD.'/files*' => Http::response([['path' => E2bSandboxProvider::ENV_FILE]]),
        E2B_ENVD.'/process.Process/Start' => Http::response(envdRan()),
    ]);

    $id = $this->e2b->create(new SandboxSpec('onedrop-project-1-x', ['ANTHROPIC_API_KEY' => "sk-it's-secret"], 8000, shellPort: 7681));

    expect($id)->toBe(E2B_ID);

    Http::assertSent(fn (Request $request) => $request->url() === E2B_API.'/v2/sandboxes'
        && $request->hasHeader('X-API-Key', 'e2b-test-key')
        && $request['templateID'] === 'onedrop-sandbox'
        && $request['secure'] === true
        && $request['network'] === ['allowPublicTraffic' => false]
        && $request['autoPause'] === true
        && $request['autoResume'] === ['enabled' => true]
        && $request['timeout'] === 600
        && $request['metadata'][E2bSandboxProvider::BUILD_METADATA] === 'build-7'
        && $request['metadata'][E2bSandboxProvider::DOCKER_METADATA] === 'on'
        && ! str_contains($request->body(), 'sk-it'));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), E2B_ENVD.'/files')
        && str_contains(urldecode($request->url()), 'path='.E2bSandboxProvider::ENV_FILE)
        && $request->hasHeader('X-Access-Token', 'envd-secret')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('sandbox:'))
        && str_contains($request->body(), "ANTHROPIC_API_KEY='sk-it'\\''s-secret'")
        && str_contains($request->body(), "ONEDROP_DOCKER='1'"));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/process.Process/Start') && envdProcess($request)['args'] === ['--kill-after=5', '120', 'chmod', '600', E2bSandboxProvider::ENV_FILE]);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/process.Process/Start') && envdProcess($request)['args'] === ['--kill-after=5', '120', '/opt/onedrop/restart']);
})->group('SBX-014');

test('a template without a finished build says to build it, and makes no sandbox', function (array $templates, string $message) {
    Http::fake([E2B_API.'/templates' => Http::response($templates)]);

    expect(fn () => $this->e2b->checkImage())->toThrow(SandboxException::class, $message);
    Http::assertSentCount(1);
})->with([
    'none' => [[], "isn't on E2B yet. Run `php artisan sandbox:build-image`."],
    'still building' => [e2bTemplates('building', '00000000-0000-0000-0000-000000000000'), 'has no finished build on E2B yet'],
])->group('SBX-014');

test('exec runs the command as the sandbox user in the project, with its env in the body, and reads the streamed output', function () {
    Http::fake([
        E2B_API.'/v2/sandboxes/'.E2B_ID.'/connect' => Http::response(e2bConnected()),
        E2B_ENVD.'/process.Process/Start' => Http::response(envdRan('out', 'err', 3)),
    ]);

    $result = $this->e2b->exec(E2B_ID, ['bash', '-c', 'echo "$1"', 'x', "it's"], ['OPENAI_API_KEY' => 'sk-secret']);

    expect($result->exitCode)->toBe(3)->and($result->output)->toBe('out')->and($result->errorOutput)->toBe('err');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/process.Process/Start')) {
            return false;
        }

        $process = envdProcess($request);

        return $process['cmd'] === 'timeout'
            && $process['args'] === ['--kill-after=5', '120', 'bash', '-c', 'echo "$1"', 'x', "it's"]
            && $process['envs'] === ['HOME' => '/home/sandbox', 'OPENAI_API_KEY' => 'sk-secret']
            && $process['cwd'] === '/workspace'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sandbox:'))
            && $request->hasHeader('Content-Type', 'application/connect+json')
            && $request->hasHeader('Keepalive-Ping-Interval', '50');
    });
})->group('SBX-014');

test('a zero exit code, root, a detached command and a command that ran too long', function () {
    Http::fake([
        E2B_API.'/v2/sandboxes/'.E2B_ID.'/connect' => Http::response(e2bConnected()),
        E2B_ENVD.'/process.Process/Start' => Http::sequence()
            ->push(envdRan('fine'))
            ->push(envdRan())
            ->push(envdRan('partial', '', 124)),
    ]);

    expect($this->e2b->exec(E2B_ID, ['true'], root: true)->exitCode)->toBe(0)
        ->and($this->e2b->exec(E2B_ID, ['node', '/opt/onedrop/forwarder.mjs'], detach: true)->successful())->toBeTrue();

    $slow = $this->e2b->exec(E2B_ID, ['sleep', '999']);

    expect($slow->exitCode)->toBe(124)->and($slow->errorOutput)->toContain('Timed out after 120 seconds');

    $starts = Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => str_ends_with($request->url(), '/process.Process/Start'))->values();

    expect($starts[0]->header('Authorization')[0])->toBe('Basic '.base64_encode('root:'))
        ->and(envdProcess($starts[0])['envs']['HOME'])->toBe('/root')
        // Started in the background, so it outlives the call.
        ->and(envdProcess($starts[1]))->toMatchArray(['cmd' => '/bin/bash', 'args' => ['-c', 'setsid nohup "$@" >/dev/null 2>&1 < /dev/null &', 'onedrop-detach', 'node', '/opt/onedrop/forwarder.mjs']]);
    // The sandbox's tokens are asked for once.
    Http::assertSentCount(4);
})->group('SBX-014');

test('envd\'s errors say what failed', function () {
    Http::fake([
        E2B_API.'/v2/sandboxes/'.E2B_ID.'/connect' => Http::response(e2bConnected()),
        E2B_ENVD.'/process.Process/Start' => Http::response(envdStream([], ['code' => 'not_found', 'message' => 'executable file not found'])),
    ]);

    expect(fn () => $this->e2b->exec(E2B_ID, ['nope']))->toThrow(SandboxException::class, 'E2B: executable file not found');
})->group('SBX-014');

test('previews are private links whose token the gateway sends as E2B\'s header; SSH has none', function () {
    Http::fake([E2B_API.'/v2/sandboxes/'.E2B_ID.'/connect' => Http::response(e2bConnected())]);

    $url = $this->e2b->previewUrl(E2B_ID, 8000);

    expect($url)->toBe('https://8000-'.E2B_ID.'.e2b.app/?e2b_traffic_token=traffic-secret')
        ->and($this->e2b->previewUrl(E2B_ID, (int) config('sandbox.ssh_port')))->toBeNull()
        ->and(Gateway::PROVIDER_TOKENS[E2bSandboxProvider::TRAFFIC_TOKEN])->toBe('e2b-traffic-access-token');
})->group('SBX-014');

test('suspend pauses it (an already paused one is fine); using it puts its pause off, at most once a minute', function () {
    Cache::flush();
    Http::fake([
        E2B_API.'/sandboxes/'.E2B_ID.'/pause' => Http::sequence()->push('', 204)->push(['code' => 409, 'message' => 'already paused'], 409),
        E2B_API.'/sandboxes/'.E2B_ID.'/timeout' => Http::response('', 204),
    ]);

    $this->e2b->suspend(E2B_ID);
    $this->e2b->suspend(E2B_ID);

    expect($this->e2b->wake(E2B_ID))->toBeFalse()
        ->and($this->e2b->wake(E2B_ID))->toBeFalse();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/timeout') && $request['timeout'] === 600);
    Http::assertSentCount(3);
})->group('SBX-014');

test('a sandbox from an older template build, or made before Docker was turned on or off, is outdated', function (array $metadata, bool $outdated) {
    Http::fake([
        E2B_API.'/templates' => Http::response(e2bTemplates()),
        E2B_API.'/sandboxes/'.E2B_ID => Http::response(['sandboxID' => E2B_ID, 'metadata' => $metadata]),
    ]);

    expect($this->e2b->isOutdated(E2B_ID))->toBe($outdated);
})->with([
    'current' => [[E2bSandboxProvider::BUILD_METADATA => 'build-7', E2bSandboxProvider::DOCKER_METADATA => 'on'], false],
    'older build' => [[E2bSandboxProvider::BUILD_METADATA => 'build-6', E2bSandboxProvider::DOCKER_METADATA => 'on'], true],
    'no Docker' => [[E2bSandboxProvider::BUILD_METADATA => 'build-7', E2bSandboxProvider::DOCKER_METADATA => 'off'], true],
])->group('SBX-014');

test('destroy deletes it, and one that is already gone is fine', function () {
    Http::fake([E2B_API.'/sandboxes/'.E2B_ID => Http::sequence()->push('', 204)->push(['code' => 404, 'message' => 'not found'], 404)]);

    $this->e2b->destroy(E2B_ID);
    $this->e2b->destroy(E2B_ID);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    Http::assertSentCount(2);
})->group('SBX-014');

test('a busy E2B is waited on, other refusals say what E2B said', function () {
    Http::fake([E2B_API.'/sandboxes/'.E2B_ID.'/pause' => Http::sequence()
        ->push(['code' => 429, 'message' => 'rate limited'], 429)->push(['code' => 429, 'message' => 'rate limited'], 429)
        ->push(['code' => 429, 'message' => 'rate limited'], 429)->push(['code' => 429, 'message' => 'rate limited'], 429)
        ->push(['code' => 403, 'message' => 'Team is blocked'], 403)]);
    Sleep::fake();

    expect(fn () => $this->e2b->suspend(E2B_ID))->toThrow(TemporarySandboxException::class, 'E2B is busy right now')
        ->and(fn () => $this->e2b->suspend(E2B_ID))->toThrow(SandboxException::class, 'E2B: Team is blocked');
})->group('SBX-014');

test('in a web request, nothing waits on E2B once the request has no time left', function () {
    app(SandboxWaitLimit::class)->start(0);
    Http::fake();

    expect(fn () => $this->e2b->suspend(E2B_ID))->toThrow(TemporarySandboxException::class, RuntimeSandboxProvider::NO_ANSWER);
    Http::assertNothingSent();
})->group('SBX-014');

test('using a sandbox on E2B keeps it awake; other providers\' sandboxes aren\'t asked', function () {
    $provider = new class extends FakeSandboxProvider
    {
        /** @var list<string> */
        public array $touched = [];

        public function wake(string $id): bool
        {
            $this->touched[] = $id;

            return false;
        }
    };
    app()->instance(SandboxProvider::class, $provider);
    $this->withoutDefer();
    $e2b = Sandbox::factory()->create(['provider' => 'e2b', 'external_id' => 'e2b-1', 'last_active_at' => now()->subMinute()]);
    $runtime = Sandbox::factory()->create(['provider' => 'runtime', 'external_id' => 'rt-1', 'last_active_at' => now()->subMinute()]);

    $e2b->markActive();
    $runtime->markActive();

    expect($provider->touched)->toBe(['e2b-1']);
})->group('SBX-014');

test('building on E2B makes a template of the published sandbox image, with the key in the environment', function () {
    config(['sandbox.provider' => 'e2b', 'sandbox.providers.e2b.api_key' => 'e2b-secret']);
    Process::fake();

    $this->artisan('sandbox:build-image')->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['npm', 'ci', '--silent'] && str_ends_with($process->path, 'infra/e2b'));
    Process::assertRan(fn ($process) => $process->command === ['node', 'build.mjs']
        && $process->environment['E2B_API_KEY'] === 'e2b-secret'
        && $process->environment['E2B_TEMPLATE'] === 'onedrop-sandbox'
        && $process->environment['E2B_SOURCE_IMAGE'] === 'ghcr.io/onedrop-io/onedrop-sandbox:latest'
        && $process->environment['E2B_MEMORY_MB'] === '4096');
})->group('SBX-014');

test('building on E2B without a key says what to set', function () {
    config(['sandbox.provider' => 'e2b', 'sandbox.providers.e2b.api_key' => null]);
    Process::fake();

    $this->artisan('sandbox:build-image')->assertFailed()->expectsOutputToContain('E2B_API_KEY');

    Process::assertNothingRan();
})->group('SBX-014');

test('without the Cloudflare gateway, which alone can send the token as a header, sandboxes\' ports are public and their links carry none', function () {
    $public = new E2bSandboxProvider(['api_key' => 'e2b-test-key', 'url' => E2B_API, 'domain' => 'e2b.app', 'image' => 'onedrop-sandbox', 'idle_seconds' => 600, 'private_previews' => false]);
    Http::fake([
        E2B_API.'/templates' => Http::response(e2bTemplates()),
        E2B_API.'/v2/sandboxes' => Http::response(e2bConnected()),
        E2B_ENVD.'/files*' => Http::response([['path' => E2bSandboxProvider::ENV_FILE]]),
        E2B_ENVD.'/process.Process/Start' => Http::response(envdRan()),
    ]);

    $id = $public->create(new SandboxSpec('onedrop-project-1-x', [], 8000));

    expect($public->previewUrl($id, 8000))->toBe('https://8000-'.E2B_ID.'.e2b.app/');
    Http::assertSent(fn (Request $request) => $request->url() === E2B_API.'/v2/sandboxes'
        && $request['network'] === ['allowPublicTraffic' => true]
        && $request['metadata'][E2bSandboxProvider::PRIVATE_METADATA] === 'no');
})->group('SBX-014');

test('a sandbox made with private ports is outdated once the gateway that sends their token is gone, and the other way round', function () {
    Http::fake([
        E2B_API.'/templates' => Http::response(e2bTemplates()),
        E2B_API.'/sandboxes/'.E2B_ID => Http::response(['sandboxID' => E2B_ID, 'metadata' => [E2bSandboxProvider::BUILD_METADATA => 'build-7', E2bSandboxProvider::DOCKER_METADATA => 'on', E2bSandboxProvider::PRIVATE_METADATA => 'yes']]),
    ]);
    $public = new E2bSandboxProvider(['api_key' => 'e2b-test-key', 'url' => E2B_API, 'domain' => 'e2b.app', 'image' => 'onedrop-sandbox', 'idle_seconds' => 600, 'private_previews' => false]);

    expect($this->e2b->isOutdated(E2B_ID))->toBeFalse()
        ->and($public->isOutdated(E2B_ID))->toBeTrue();
})->group('SBX-014');

test('the install\'s E2B previews are private only behind the Cloudflare gateway', function (array $gateway, bool $private) {
    config(['sandbox.provider' => 'e2b', 'sandbox.providers.e2b.api_key' => 'k', ...$gateway]);
    app()->forgetInstance(SandboxProvider::class);
    $e2b = app(SandboxProvider::class)->provider('e2b');

    expect((new ReflectionMethod($e2b, 'privatePreviews'))->invoke($e2b))->toBe($private);
})->with([
    'Cloudflare Worker' => [['sandbox.gateway_domain' => 'onedrop.io', 'sandbox.gateway_secret' => 's'], true],
    'Caddy on a server' => [['sandbox.gateway_domain' => 'onedrop.example', 'sandbox.gateway_secret' => null], false],
    'no gateway' => [['sandbox.gateway_domain' => null, 'sandbox.gateway_secret' => null], false],
])->group('SBX-014');
