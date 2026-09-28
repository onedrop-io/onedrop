<?php

use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const RT_API = 'https://api.withruntime.com/v1';
const RT_ID = 'ad9e8852-6672-421f-b015-214d76c96a51';

beforeEach(function () {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();

    $this->runtimeConfig = [
        'api_key' => 'rt-test-key',
        'url' => 'https://api.withruntime.com',
        'image' => 'zap-sandbox:latest',
        'funding' => 'trial',
        'vcpu' => 2,
        'memory_mib' => 4096,
        'disk_mib' => 8192,
        'timeout_seconds' => 3600,
        'persistent' => false,
    ];
    $this->runtime = new RuntimeSandboxProvider($this->runtimeConfig);
});

function runtimeError(string $code, int $status, string $message = 'Refused.', string $hint = 'Do the thing.'): array
{
    return ['error' => ['code' => $code, 'status' => $status, 'message' => $message, 'hint' => $hint, 'requestId' => 'req_123']];
}

test('create starts a trial sandbox from the current image and hands it its settings in a private file', function () {
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(['id' => RT_ID, 'state' => 'running']),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response(['path' => RuntimeSandboxProvider::ENV_FILE]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    $id = $this->runtime->create(new SandboxSpec('zap-project-1-x', ['ANTHROPIC_API_KEY' => "sk-it's-secret"], 8000, shellPort: 7681));

    expect($id)->toBe(RT_ID);

    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/sandboxes'
        && $request->hasHeader('Authorization', 'Bearer rt-test-key')
        && $request->hasHeader('Idempotency-Key')
        && $request['image'] === 'img-7'
        && $request['labels'] === [RuntimeSandboxProvider::IMAGE_LABEL => 'img-7']
        && $request['funding'] === 'trial'
        && ! isset($request['persistent'])
        && ! str_contains($request->body(), 'sk-it'));

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), 'mode=600')
        && str_contains(urldecode($request->url()), 'path='.RuntimeSandboxProvider::ENV_FILE)
        && str_contains($request->body(), "ANTHROPIC_API_KEY='sk-it'\\''s-secret'")
        && str_contains($request->body(), "SHELL_PORT='7681'"));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':exec') && $request['argv'] === ['/opt/zap/restart']);
})->group('SBX-003');

test('create says to build the image when it is not on Runtime yet', function () {
    Http::fake([RT_API.'/images/resolve*' => Http::response(runtimeError('not_found', 404), 404)]);

    expect(fn () => $this->runtime->create(new SandboxSpec('zap-project-1-x')))
        ->toThrow(SandboxException::class, 'php artisan sandbox:build-image');
})->group('SBX-003');

test('a sandbox that cannot take its settings is stopped instead of left running', function () {
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(['id' => RT_ID]),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response(runtimeError('permission_denied', 403), 403),
        RT_API.'/sandboxes/'.RT_ID.':stop' => Http::response(['id' => RT_ID, 'state' => 'stopped']),
    ]);

    expect(fn () => $this->runtime->create(new SandboxSpec('zap-project-1-x')))->toThrow(SandboxException::class);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':stop'));
})->group('SBX-003');

test('paid persistent sandboxes are asked for only when configured', function () {
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(['id' => RT_ID]),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response([]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    (new RuntimeSandboxProvider([...$this->runtimeConfig, 'funding' => 'paid', 'persistent' => true]))->create(new SandboxSpec('zap-project-1-x'));

    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/sandboxes'
        && $request['funding'] === 'paid' && $request['persistent'] === true);
})->group('SBX-003');

test('exec passes secrets in the body, never the command line, and maps the result', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 3, 'stdout' => 'out', 'stderr' => 'err', 'timedOut' => false])]);

    $result = $this->runtime->exec(RT_ID, ['node', '/opt/zap/forwarder.mjs'], ['OPENAI_API_KEY' => 'sk-secret']);

    expect($result->exitCode)->toBe(3)
        ->and($result->output)->toBe('out')
        ->and($result->errorOutput)->toBe('err');

    Http::assertSent(fn (Request $request) => $request['argv'] === ['node', '/opt/zap/forwarder.mjs']
        && $request['env'] === ['OPENAI_API_KEY' => 'sk-secret']);
})->group('SBX-003');

test('a detached exec starts a background process', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.'/processes' => Http::response(['id' => 'p1', 'state' => 'running'])]);

    expect($this->runtime->exec(RT_ID, ['node', '/opt/zap/forwarder.mjs'], detach: true)->successful())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/processes') && ! isset($request['timeoutMs']));
})->group('SBX-003');

test('a command that times out is a failed result', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => null, 'stdout' => '', 'stderr' => '', 'timedOut' => true])]);

    $result = $this->runtime->exec(RT_ID, ['sleep', '999']);

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput)->toContain('Timed out');
})->group('SBX-003');

test('the preview url is a private link that carries its token', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.'/previews' => Http::response([
        'url' => 'https://8081-abc.runtimehost.com/',
        'urlWithToken' => 'https://8081-abc.runtimehost.com/?runtime_preview_token=t',
    ])]);

    expect($this->runtime->previewUrl(RT_ID, 8081))->toBe('https://8081-abc.runtimehost.com/?runtime_preview_token=t');

    Http::assertSent(fn (Request $request) => $request['port'] === 8081
        && $request['ttlSeconds'] === RuntimeSandboxProvider::PREVIEW_TTL_SECONDS
        && ! isset($request['visibility']));
})->group('SBX-003');

test('the ssh port gets no https preview', function () {
    expect($this->runtime->previewUrl(RT_ID, config('sandbox.ssh_port')))->toBeNull();

    Http::assertNothingSent();
})->group('SBX-003');

test('start wakes a paused sandbox and leaves a running one alone', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':wake' => Http::response(runtimeError('not_paused', 409), 409)]);

    $this->runtime->start(RT_ID);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Prefer', 'wait=60'));
})->group('SBX-003');

test('pause pauses the sandbox', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':pause' => Http::response(['state' => 'paused'])]);

    $this->runtime->pause(RT_ID);

    Http::assertSentCount(1);
})->group('SBX-003');

test('destroying a sandbox that is already gone is not an error', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':stop' => Http::response(runtimeError('not_found', 404), 404)]);

    $this->runtime->destroy(RT_ID);

    Http::assertSentCount(1);
})->group('SBX-003');

test('runtime errors reach the user with their hint and request id', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':pause' => Http::response(runtimeError('trial_exhausted', 402, 'The trial is used up.', 'Add credit.'), 402)]);

    expect(fn () => $this->runtime->pause(RT_ID))
        ->toThrow(SandboxException::class, 'Runtime: The trial is used up. Add credit. (req_123)');
})->group('SBX-003');

test('temporary refusals are retried with the same idempotency key', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':pause' => Http::sequence()
        ->push(runtimeError('busy', 503), 503)
        ->push(['state' => 'paused'])]);

    $this->runtime->pause(RT_ID);

    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0]);
    expect($keys)->toHaveCount(2)->and($keys->unique())->toHaveCount(1);
})->group('SBX-003');

test('a missing api key is explained', function () {
    expect(fn () => (new RuntimeSandboxProvider([...$this->runtimeConfig, 'api_key' => null]))->pause(RT_ID))
        ->toThrow(SandboxException::class, 'RUNTIME_API_KEY is not set');
})->group('SBX-003');

test('a sandbox is outdated when it was made from another image version', function (string $label, bool $outdated) {
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID => Http::response(['id' => RT_ID, 'labels' => [RuntimeSandboxProvider::IMAGE_LABEL => $label]]),
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-8']),
    ]);

    expect($this->runtime->isOutdated(RT_ID))->toBe($outdated);
})->with([
    'older image' => ['img-7', true],
    'current image' => ['img-8', false],
])->group('SBX-003');

test('a sandbox is not outdated when there is no image to move to', function () {
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID => Http::response(['id' => RT_ID, 'labels' => [RuntimeSandboxProvider::IMAGE_LABEL => 'img-7']]),
        RT_API.'/images/resolve*' => Http::response(runtimeError('not_found', 404), 404),
    ]);

    expect($this->runtime->isOutdated(RT_ID))->toBeFalse();
})->group('SBX-003');

test('copying out a path the sandbox never created copies nothing', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 3, 'stdout' => '', 'stderr' => '', 'timedOut' => false])]);

    $this->runtime->copyOut(RT_ID, '/data/storage', sys_get_temp_dir());

    Http::assertSentCount(1);
})->group('SBX-003');

test('copying in uploads an archive, unpacks it as root, and hands it to the sandbox user', function () {
    Process::fake(['*' => Process::result()]);
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response([]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    $this->runtime->copyIn(RT_ID, sys_get_temp_dir(), '/home/sandbox/.local/share/opencode');

    Process::assertRan(fn ($process) => $process->command[0] === 'tar' && $process->command[1] === '-czf');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':exec')
        && $request['argv'][0] === 'sudo'
        && in_array('/home/sandbox/.local/share/opencode', $request['argv'], true)
        && end($request->data()['argv']) === '/home/sandbox');
})->group('SBX-003');
