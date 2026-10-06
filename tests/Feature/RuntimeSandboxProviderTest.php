<?php

use App\Sandbox\AppProcesses;
use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

const RT_API = 'https://api.withruntime.com/v1';
const RT_ID = 'ad9e8852-6672-421f-b015-214d76c96a51';

beforeEach(function () {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();

    $this->runtimeConfig = [
        'api_key' => 'rt-test-key',
        'url' => 'https://api.withruntime.com',
        'image' => 'onedrop-sandbox:latest',
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

    $id = $this->runtime->create(new SandboxSpec('onedrop-project-1-x', ['ANTHROPIC_API_KEY' => "sk-it's-secret"], 8000, shellPort: 7681));

    expect($id)->toBe(RT_ID);

    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/sandboxes'
        && $request->hasHeader('Authorization', 'Bearer rt-test-key')
        && $request->hasHeader('Idempotency-Key')
        && $request['image'] === 'img-7'
        && $request['labels'] === [RuntimeSandboxProvider::IMAGE_LABEL => 'img-7', RuntimeSandboxProvider::DOCKER_LABEL => 'off']
        && $request['funding'] === 'trial'
        && ! isset($request['persistent'])
        && ! str_contains($request->body(), 'sk-it'));

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_contains($request->url(), 'mode=600')
        && str_contains(urldecode($request->url()), 'path='.RuntimeSandboxProvider::ENV_FILE)
        && str_contains($request->body(), "ANTHROPIC_API_KEY='sk-it'\\''s-secret'")
        && str_contains($request->body(), "SHELL_PORT='7681'")
        && ! str_contains($request->body(), 'ONEDROP_DOCKER'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':exec') && $request['argv'] === ['/opt/onedrop/restart']);
})->group('SBX-003');

test('with Docker inside sandboxes on, a sandbox is labelled for it and its settings tell start.sh to start Docker', function () {
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(['id' => RT_ID, 'state' => 'running']),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response(['path' => RuntimeSandboxProvider::ENV_FILE]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    (new RuntimeSandboxProvider([...$this->runtimeConfig, 'nested_docker' => 'on']))->create(new SandboxSpec('onedrop-project-1-x'));

    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/sandboxes'
        && $request['labels'] === [RuntimeSandboxProvider::IMAGE_LABEL => 'img-7', RuntimeSandboxProvider::DOCKER_LABEL => 'on']);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_contains($request->body(), "ONEDROP_DOCKER='1'"));
})->group('SBX-008');

test('turning Docker inside sandboxes on or off makes existing Runtime sandboxes outdated', function (string $mode, ?string $label, bool $outdated) {
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID => Http::response(['id' => RT_ID, 'labels' => array_filter([RuntimeSandboxProvider::IMAGE_LABEL => 'img-8', RuntimeSandboxProvider::DOCKER_LABEL => $label])]),
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-8']),
    ]);

    expect((new RuntimeSandboxProvider([...$this->runtimeConfig, 'nested_docker' => $mode]))->isOutdated(RT_ID))->toBe($outdated);
})->with([
    'on, sandbox made before it' => ['on', null, true],
    'on, sandbox made with it' => ['on', 'on', false],
    'off, sandbox made with it' => ['off', 'on', true],
    'off, sandbox made before it' => ['off', null, false],
])->group('SBX-008');

test('create says to build the image when it is not on Runtime yet', function () {
    Http::fake([RT_API.'/images/resolve*' => Http::response(runtimeError('not_found', 404), 404)]);

    expect(fn () => $this->runtime->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, 'php artisan sandbox:build-image');
})->group('SBX-003');

test('a sandbox that cannot take its settings is stopped instead of left running', function () {
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(['id' => RT_ID]),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response(runtimeError('permission_denied', 403), 403),
        RT_API.'/sandboxes/'.RT_ID.':stop' => Http::response(['id' => RT_ID, 'state' => 'stopped']),
    ]);

    expect(fn () => $this->runtime->create(new SandboxSpec('onedrop-project-1-x')))->toThrow(SandboxException::class);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':stop'));
})->group('SBX-003');

test('paid persistent sandboxes are asked for only when configured', function () {
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(['id' => RT_ID]),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response([]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    (new RuntimeSandboxProvider([...$this->runtimeConfig, 'funding' => 'paid', 'persistent' => true]))->create(new SandboxSpec('onedrop-project-1-x'));

    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/sandboxes'
        && $request['funding'] === 'paid' && $request['persistent'] === true);
})->group('SBX-003');

test('exec passes secrets in the body, never the command line, and maps the result', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 3, 'stdout' => 'out', 'stderr' => 'err', 'timedOut' => false])]);

    $result = $this->runtime->exec(RT_ID, ['node', '/opt/onedrop/forwarder.mjs'], ['OPENAI_API_KEY' => 'sk-secret']);

    expect($result->exitCode)->toBe(3)
        ->and($result->output)->toBe('out')
        ->and($result->errorOutput)->toBe('err');

    Http::assertSent(fn (Request $request) => $request['argv'] === ['node', '/opt/onedrop/forwarder.mjs']
        && $request['env'] === ['HOME' => RuntimeSandboxProvider::HOME, 'OPENAI_API_KEY' => 'sk-secret']);
})->group('SBX-003');

test('exec as root goes through sudo, keeping only the env it was given', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false])]);

    $this->runtime->exec(RT_ID, ['rm', '-rf', '/tmp/x'], root: true);
    $this->runtime->exec(RT_ID, ['tool'], ['TOKEN' => 'secret'], root: true);

    Http::assertSent(fn (Request $request) => $request['argv'] === ['sudo', 'rm', '-rf', '/tmp/x']);
    Http::assertSent(fn (Request $request) => $request['argv'] === ['sudo', '--preserve-env=TOKEN', 'tool']
        && $request['env']['TOKEN'] === 'secret');
})->group('TASK-003');

test('a detached exec starts a background process', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.'/processes' => Http::response(['id' => 'p1', 'state' => 'running'])]);

    expect($this->runtime->exec(RT_ID, ['node', '/opt/onedrop/forwarder.mjs'], detach: true)->successful())->toBeTrue();

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

test('public previews are asked for when configured, and carry no token', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.'/previews' => Http::response([
        'url' => 'https://8081-abc.runtimehost.com/',
        'urlWithToken' => null,
        'visibility' => 'public',
    ])]);

    $runtime = new RuntimeSandboxProvider([...$this->runtimeConfig, 'preview_visibility' => 'public']);

    expect($runtime->previewUrl(RT_ID, 8081))->toBe('https://8081-abc.runtimehost.com/');

    Http::assertSent(fn (Request $request) => $request['visibility'] === 'public' && $request['port'] === 8081);
})->group('SBX-003');

test('a trial account refusing public previews explains why', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.'/previews' => Http::response(['error' => [
        'code' => 'public_preview_not_allowed', 'status' => 403,
        'message' => 'A trial sandbox shares a port privately, with a token; public previews need a paid sandbox.',
        'hint' => 'Create the preview without visibility public.', 'requestId' => 'req_9',
    ]], 403)]);

    expect(fn () => (new RuntimeSandboxProvider([...$this->runtimeConfig, 'preview_visibility' => 'public']))->previewUrl(RT_ID, 8081))
        ->toThrow(SandboxException::class, 'public previews need a paid sandbox');
})->group('SBX-003');

test('the ssh port gets no https preview', function () {
    expect($this->runtime->previewUrl(RT_ID, config('sandbox.ssh_port')))->toBeNull();

    Http::assertNothingSent();
})->group('SBX-003');

test('pausing freezes the app\'s processes, with a watchdog that thaws them if nobody does', function () {
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
        RT_API.'/sandboxes/'.RT_ID.'/processes' => Http::response(['id' => 'p1', 'state' => 'running']),
    ]);

    $this->runtime->pause(RT_ID);

    // Not Runtime's own pause: copying the files out would wake the sandbox, and its processes with it.
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), ':pause'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':exec') && $request['argv'][2] === AppProcesses::FREEZE);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/processes')
        && str_contains($request['argv'][2], 'sleep '.RuntimeSandboxProvider::THAW_AFTER_SECONDS)
        && end($request->data()['argv']) === 'onedrop-thaw-watchdog');
})->group('SBX-003');

test('a sandbox whose processes cannot be frozen is not copied', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 1, 'stdout' => '', 'stderr' => 'pgrep: not found', 'timedOut' => false])]);

    expect(fn () => $this->runtime->pause(RT_ID))->toThrow(SandboxException::class, 'pgrep: not found');
})->group('SBX-003');

test('starting lets frozen processes carry on and calls off the watchdog', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false])]);

    $this->runtime->start(RT_ID);

    Http::assertSent(fn (Request $request) => str_contains($request['argv'][2], 'pkill -CONT') && str_contains($request['argv'][2], '[o]nedrop-thaw-watchdog'));
})->group('SBX-003');

test('suspending uses runtime\'s own pause, and one already paused is fine', function (int $status) {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':pause' => Http::response($status === 200 ? ['state' => 'paused'] : runtimeError('sandbox_paused', 409), $status)]);

    $this->runtime->suspend(RT_ID);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':pause') && $request->hasHeader('Prefer', 'wait=60'));
})->with(['running' => [200], 'already paused' => [409]])->group('SBX-003');

test('creating waits for a free slot while the trial is at its limit', function () {
    Sleep::fake();
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::sequence()
            ->push(runtimeError('trial_busy', 409, 'trial already running: at most 8 trial sandboxes run at once'), 409)
            ->push(runtimeError('trial_busy', 409), 409)
            ->push(['id' => RT_ID]),
        RT_API.'/sandboxes/'.RT_ID.'/files/content*' => Http::response([]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    expect($this->runtime->create(new SandboxSpec('onedrop-project-1-x')))->toBe(RT_ID);

    Sleep::assertSleptTimes(2);
})->group('SBX-003');

test('creating gives up once the trial stays full', function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-7']),
        RT_API.'/sandboxes' => Http::response(runtimeError('trial_busy', 409, 'trial already running: at most 8 trial sandboxes run at once'), 409),
    ]);

    expect(fn () => $this->runtime->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, 'at most 8 trial sandboxes');
})->group('SBX-003');

test('destroying a sandbox that is already gone is not an error', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':stop' => Http::response(runtimeError('not_found', 404), 404)]);

    $this->runtime->destroy(RT_ID);

    Http::assertSentCount(1);
})->group('SBX-003');

test('runtime errors reach the user with their hint and request id', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':pause' => Http::response(runtimeError('trial_exhausted', 402, 'The trial is used up.', 'Add credit.'), 402)]);

    expect(fn () => $this->runtime->suspend(RT_ID))
        ->toThrow(SandboxException::class, 'Runtime: The trial is used up. Add credit. (req_123)');
})->group('SBX-003');

test('temporary refusals are retried with the same idempotency key', function () {
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':pause' => Http::sequence()
        ->push(runtimeError('busy', 503), 503)
        ->push(['state' => 'paused'])]);

    $this->runtime->suspend(RT_ID);

    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0]);
    expect($keys)->toHaveCount(2)->and($keys->unique())->toHaveCount(1);
})->group('SBX-003');

test('a command waits while runtime has no room yet to wake the paused sandbox', function (string $code) {
    Sleep::fake();
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::sequence()
        ->push(runtimeError($code, 409), 409)
        ->push(runtimeError($code, 409), 409)
        ->push(['exitCode' => 0, 'stdout' => 'ok', 'stderr' => '', 'timedOut' => false])]);

    expect($this->runtime->exec(RT_ID, ['true'])->output)->toBe('ok');

    Http::assertSentCount(3);
})->with(['trial_busy', 'no_capacity', 'sandbox_not_ready'])->group('SBX-003');

test('a sandbox runtime still has no room to wake says to try again, and is logged', function () {
    Sleep::fake();
    Log::spy();
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(runtimeError('trial_busy', 409, 'trial already running: at most 8 trial sandboxes run at once'), 409)]);

    expect(fn () => $this->runtime->exec(RT_ID, ['true']))
        ->toThrow(SandboxException::class, 'Runtime has no room for the sandbox right now (trial already running: at most 8 trial sandboxes run at once). Try again in a moment.');

    Http::assertSentCount(7);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['code'] === 'trial_busy' && $context['request_id'] === 'req_123');
})->group('SBX-003');

test('a missing api key is explained', function () {
    expect(fn () => (new RuntimeSandboxProvider([...$this->runtimeConfig, 'api_key' => null]))->suspend(RT_ID))
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
        RT_API.'/sandboxes/'.RT_ID.'/uploads' => Http::response(['uploadId' => 'up-1', 'chunkBytes' => 8_388_608]),
        RT_API.'/sandboxes/'.RT_ID.'/uploads/up-1:commit' => Http::response(['size' => 0, 'sha256' => 'x']),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    $this->runtime->copyIn(RT_ID, sys_get_temp_dir(), '/home/sandbox/.local/share/opencode');

    Process::assertRan(fn ($process) => $process->command[0] === 'tar' && $process->command[1] === '-czf');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':exec')
        && $request['argv'][0] === 'sudo'
        && in_array('/home/sandbox/.local/share/opencode', $request['argv'], true)
        && end($request->data()['argv']) === '/home/sandbox');
})->group('SBX-003');

test('tool files are uploaded and unpacked as root, staying root\'s', function () {
    Process::fake(['*' => Process::result()]);
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID.'/uploads' => Http::response(['uploadId' => 'up-1', 'chunkBytes' => 8_388_608]),
        RT_API.'/sandboxes/'.RT_ID.'/uploads/up-1:commit' => Http::response(['size' => 0, 'sha256' => 'x']),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    expect($this->runtime->installFiles(RT_ID, sys_get_temp_dir(), '/opt/onedrop'))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':exec')
        && $request['argv'][0] === 'sudo'
        && str_contains($request['argv'][3], '--no-same-owner')
        && ! str_contains($request['argv'][3], 'chown')
        && $request['argv'][5] === '/opt/onedrop');
})->group('SBX-002');

test('large archives are uploaded in the chunks runtime asks for, checked by their digest', function () {
    // tar writes 25 bytes; Runtime asks for 10-byte chunks.
    Process::fake(function ($process) {
        file_put_contents($process->command[2], str_repeat('x', 25));

        return Process::result();
    });
    Http::fake([
        RT_API.'/sandboxes/'.RT_ID.'/uploads' => Http::response(['uploadId' => 'up-1', 'chunkBytes' => 10]),
        RT_API.'/sandboxes/'.RT_ID.'/uploads/up-1?*' => Http::response(['received' => 10]),
        RT_API.'/sandboxes/'.RT_ID.'/uploads/up-1:commit' => Http::response(['size' => 25, 'sha256' => hash('sha256', str_repeat('x', 25))]),
        RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false]),
    ]);

    $this->runtime->copyIn(RT_ID, sys_get_temp_dir(), '/workspace');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/uploads')
        && $request['size'] === 25 && $request['sha256'] === hash('sha256', str_repeat('x', 25)));

    $chunks = Http::recorded()->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'PUT')
        ->map(fn (Request $request) => [parse_url($request->url(), PHP_URL_QUERY), strlen($request->body())])->values()->all();

    expect($chunks)->toBe([['offset=0', 10], ['offset=10', 10], ['offset=20', 5]]);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':commit'));
})->group('SBX-003');

/**
 * The current image's versions (over two pages), and $former ones under the image's name from before the rename.
 */
function fakeRuntimeImages(array $sandboxes, array $former = []): void
{
    Http::fake([
        RT_API.'/images/resolve*' => Http::response(['id' => 'img-4', 'name' => 'onedrop-sandbox', 'version' => 4]),
        RT_API.'/images/*:delete' => Http::response(['id' => 'x', 'status' => 'deleted']),
        RT_API.'/images?*' => fn (Request $request) => Http::response(match (true) {
            $request['name'] !== 'onedrop-sandbox' => ['data' => $request['name'] === 'zap-sandbox' ? $former : [], 'nextCursor' => null],
            ($request['cursor'] ?? null) === null => ['data' => [
                ['id' => 'img-5', 'version' => 5, 'state' => 'failed'],
                ['id' => 'img-4', 'version' => 4, 'state' => 'ready'],
                ['id' => 'img-3', 'version' => 3, 'state' => 'ready'],
            ], 'nextCursor' => 'next'],
            default => ['data' => [
                ['id' => 'img-2', 'version' => 2, 'state' => 'ready'],
                ['id' => 'img-1', 'version' => 1, 'state' => 'failed'],
            ], 'nextCursor' => null],
        }),
        RT_API.'/sandboxes?*' => Http::response(['data' => $sandboxes, 'nextCursor' => null]),
    ]);
}

test('pruning deletes older image versions no sandbox can still use', function () {
    fakeRuntimeImages([
        ['id' => 'sbx-1', 'state' => 'paused', 'persistent' => false, 'labels' => [RuntimeSandboxProvider::IMAGE_LABEL => 'img-2']],
        ['id' => 'sbx-2', 'state' => 'stopped', 'persistent' => false, 'labels' => [RuntimeSandboxProvider::IMAGE_LABEL => 'img-3']],
    ]);

    $deleted = $this->runtime->pruneImages();

    expect(array_column($deleted, 'id'))->toBe(['img-1', 'img-3']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'images?') && $request['name'] === 'onedrop-sandbox');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'cursor=next'));
    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/images/img-1:delete');
    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/images/img-3:delete');
    // The current version, the newer failed build, and the one a paused sandbox came from stay.
    Http::assertNotSent(fn (Request $request) => preg_match('/img-(2|4|5):delete/', $request->url()) === 1);
})->group('SBX-003');

test('pruning keeps the images of stopped persistent sandboxes and of sandboxes from before the rename', function () {
    fakeRuntimeImages([
        ['id' => 'sbx-1', 'state' => 'stopped', 'persistent' => true, 'labels' => [RuntimeSandboxProvider::IMAGE_LABEL => 'img-3']],
        ['id' => 'sbx-2', 'state' => 'running', 'persistent' => false, 'labels' => ['zap.image' => 'img-1']],
    ]);

    expect(array_column($this->runtime->pruneImages(), 'id'))->toBe(['img-2']);
})->group('SBX-003');

test('pruning deletes every version under the image\'s name from before the rename, unless a sandbox still needs it', function () {
    fakeRuntimeImages([
        ['id' => 'sbx-1', 'state' => 'paused', 'persistent' => false, 'labels' => ['zap.image' => 'zap-12']],
    ], former: [
        ['id' => 'zap-13', 'version' => 13, 'state' => 'ready'],
        ['id' => 'zap-12', 'version' => 12, 'state' => 'ready'],
        ['id' => 'zap-4', 'version' => 4, 'state' => 'failed'],
    ]);

    expect(array_column($this->runtime->pruneImages(), 'id'))->toBe(['img-1', 'img-2', 'img-3', 'zap-4', 'zap-13']);
    Http::assertSent(fn (Request $request) => $request->url() === RT_API.'/images/zap-13:delete');
    Http::assertNotSent(fn (Request $request) => $request->url() === RT_API.'/images/zap-12:delete');
})->group('SBX-003');

test('a dry run of pruning deletes nothing', function () {
    fakeRuntimeImages([]);

    expect(array_column($this->runtime->pruneImages(dryRun: true), 'id'))->toBe(['img-1', 'img-2', 'img-3']);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), ':delete'));
})->group('SBX-003');

test('pruning does nothing before the image is built', function () {
    Http::fake([RT_API.'/images/resolve*' => Http::response(runtimeError('image_not_found', 404), 404)]);

    expect($this->runtime->pruneImages())->toBe([]);
    Http::assertSentCount(1);
})->group('SBX-003');

test('a command a busy sandbox refused goes again when Runtime says, with the same idempotency key', function () {
    Sleep::fake();
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::sequence()
        ->push(runtimeError('guest_busy', 503, 'The sandbox is running as many commands as it can at once. Nothing ran.'), 503, ['Retry-After' => '2'])
        ->push(runtimeError('guest_busy', 503), 503, ['Retry-After' => '600'])
        ->push(['exitCode' => 0, 'stdout' => 'ok', 'stderr' => '', 'timedOut' => false])]);

    expect($this->runtime->exec(RT_ID, ['true'])->output)->toBe('ok');

    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0]);
    expect($keys)->toHaveCount(3)->and($keys->unique())->toHaveCount(1);
    // Retry-After, but never longer than the limit.
    Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(RuntimeSandboxProvider::MAX_RETRY_WAIT_MS)->milliseconds()]);
})->group('SBX-003');

test('a sandbox that stays busy says so in plain words', function () {
    Sleep::fake();
    Http::fake([RT_API.'/sandboxes/'.RT_ID.':exec' => Http::response(runtimeError('guest_busy', 503, 'The sandbox is running as many commands as it can at once. Nothing ran.'), 503, ['Retry-After' => '1'])]);

    expect(fn () => $this->runtime->exec(RT_ID, ['true']))
        ->toThrow(SandboxException::class, 'The sandbox is busy running other commands. Try again in a moment.');

    Http::assertSentCount(7);
})->group('SBX-003');
