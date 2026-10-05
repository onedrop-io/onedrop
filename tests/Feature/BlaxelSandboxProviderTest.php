<?php

use App\Sandbox\AppProcesses;
use App\Sandbox\Providers\BlaxelSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxSpec;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const BL_API = 'https://api.blaxel.ai/v0';
const BL_SBX = 'https://sbx-onedrop-project-1-x-ws.us-pdx-1.bl.run';

beforeEach(function () {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();

    $this->blaxelConfig = [
        'api_key' => 'bl-test-key',
        'workspace' => 'onedrop',
        'url' => 'https://api.blaxel.ai',
        'image' => 'onedrop-sandbox',
        'memory_mib' => 4096,
        'region' => null,
        'cli' => 'bl',
    ];
    $this->blaxel = new BlaxelSandboxProvider($this->blaxelConfig);
});

function blaxelImage(): array
{
    return ['spec' => ['tags' => [
        ['name' => 'old1', 'createdAt' => '2026-09-28T16:40:24Z'],
        ['name' => 'new2', 'createdAt' => '2026-09-28T16:47:02Z'],
    ]]];
}

function blaxelSandbox(string $status = 'DEPLOYED', array $labels = []): array
{
    return ['metadata' => ['name' => 'onedrop-project-1-x', 'url' => BL_SBX, 'labels' => $labels], 'status' => $status];
}

function blaxelProcess(int $exitCode = 0, string $stdout = '', string $stderr = ''): array
{
    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'status' => 'completed'];
}

test('create starts a sandbox from the newest image with its ports and secret settings', function () {
    Http::fake([
        BL_API.'/images/sandbox/onedrop-sandbox' => Http::response(blaxelImage()),
        BL_API.'/sandboxes' => Http::response(blaxelSandbox('DEPLOYING')),
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/health' => Http::response(['status' => 'ok']),
    ]);

    $id = $this->blaxel->create(new SandboxSpec('onedrop-project-1-x', ['ANTHROPIC_API_KEY' => 'sk-secret'], 8000, shellPort: 7681, proxyPort: 8081));

    expect($id)->toBe('onedrop-project-1-x');

    Http::assertSent(function (Request $request) {
        if ($request->url() !== BL_API.'/sandboxes' || $request->method() !== 'POST') {
            return false;
        }

        $envs = collect($request['spec']['runtime']['envs'])->keyBy('name');

        return $request->hasHeader('Authorization', 'Bearer bl-test-key')
            && $request->hasHeader('X-Blaxel-Workspace', 'onedrop')
            && $request['spec']['runtime']['image'] === 'sandbox/onedrop-sandbox:new2'
            && $request['metadata']['labels'] === [BlaxelSandboxProvider::IMAGE_LABEL => 'sandbox/onedrop-sandbox:new2']
            && collect($request['spec']['runtime']['ports'])->pluck('target')->all() === [8000, 8081, 7681]
            && $envs['ANTHROPIC_API_KEY'] === ['name' => 'ANTHROPIC_API_KEY', 'value' => 'sk-secret', 'secret' => true]
            && $envs['ONEDROP_PORT']['value'] === '8000' && $envs['ONEDROP_PORT']['secret'] === false
            && ! isset($request['spec']['region']);
    });
})->group('SBX-004');

test('create says to build the image when it is not on Blaxel yet', function () {
    Http::fake([BL_API.'/images/sandbox/onedrop-sandbox' => Http::response(['error' => 'not found'], 404)]);

    expect(fn () => $this->blaxel->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, 'php artisan sandbox:build-image');
})->group('SBX-004');

test('a sandbox that fails to start is deleted and the reason shown', function () {
    Http::fake([
        BL_API.'/images/sandbox/onedrop-sandbox' => Http::response(blaxelImage()),
        BL_API.'/sandboxes' => Http::response(blaxelSandbox('DEPLOYING')),
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::sequence()
            ->push([...blaxelSandbox('FAILED'), 'events' => [['message' => 'Image pull failed']]])
            ->push(['deleted' => true]),
    ]);

    expect(fn () => $this->blaxel->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, 'Image pull failed');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
})->group('SBX-004');

test('account limits reach the user in blaxel\'s words, and nothing is left behind', function () {
    Http::fake([
        BL_API.'/images/sandbox/onedrop-sandbox' => Http::response(blaxelImage()),
        BL_API.'/sandboxes' => Http::response(['error' => 'You have reached the maximum memory (4096) for your account. Requested: 8192'], 400),
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(['error' => 'not found'], 404),
    ]);

    expect(fn () => $this->blaxel->create(new SandboxSpec('onedrop-project-1-x')))
        ->toThrow(SandboxException::class, 'Blaxel: You have reached the maximum memory (4096)');

    // A refused create may still have made the sandbox.
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sandboxes/onedrop-project-1-x'));
})->group('SBX-004');

test('exec quotes the command, passes secrets in the body, and puts the app port back', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/process' => Http::response(blaxelProcess(3, 'out', 'err')),
    ]);

    $result = $this->blaxel->exec('onedrop-project-1-x', ['bash', '-c', 'echo "$1"', 'x', "it's"], ['OPENAI_API_KEY' => 'sk-secret']);

    expect($result->exitCode)->toBe(3)->and($result->output)->toBe('out')->and($result->errorOutput)->toBe('err');

    Http::assertSent(fn (Request $request) => $request->url() === BL_SBX.'/process'
        && $request['command'] === "'bash' '-c' 'echo \"\$1\"' 'x' 'it'\\''s'"
        && $request['env'] === ['PORT' => (string) config('sandbox.port'), 'OPENAI_API_KEY' => 'sk-secret']
        && $request['waitForCompletion'] === true
        && ! str_contains($request['command'], 'sk-secret'));
})->group('SBX-004');

test('a detached exec keeps the sandbox awake until it ends', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/process' => Http::response(blaxelProcess()),
    ]);

    expect($this->blaxel->exec('onedrop-project-1-x', ['node', '/opt/onedrop/forwarder.mjs'], detach: true)->successful())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === BL_SBX.'/process'
        && $request['waitForCompletion'] === false && $request['keepAlive'] === true && $request['timeout'] === 0);
})->group('SBX-004');

test('a command that times out is a failed result', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/process' => Http::response(['error' => 'process timeout exceeded'], 422),
    ]);

    $result = $this->blaxel->exec('onedrop-project-1-x', ['sleep', '999']);

    expect($result->successful())->toBeFalse()->and($result->errorOutput)->toContain('Timed out');
})->group('SBX-004');

test('the preview url is a private link that carries a token', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x/previews' => Http::response(['spec' => ['url' => 'https://abc.preview.bl.run']]),
        BL_API.'/sandboxes/onedrop-project-1-x/previews/port-8081/tokens' => Http::response(['spec' => ['token' => 'tok']]),
    ]);

    expect($this->blaxel->previewUrl('onedrop-project-1-x', 8081))->toBe('https://abc.preview.bl.run/?bl_preview_token=tok');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/previews')
        && $request['metadata']['name'] === 'port-8081'
        && $request['spec'] === ['port' => 8081, 'public' => false]);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tokens')
        && str_starts_with($request['spec']['expiresAt'], now()->addDays(BlaxelSandboxProvider::PREVIEW_TOKEN_DAYS)->format('Y-m-d')));
})->group('SBX-004');

test('an existing preview gets a fresh token', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x/previews' => Http::response(['error' => 'already exists'], 409),
        BL_API.'/sandboxes/onedrop-project-1-x/previews/port-7681' => Http::response(['spec' => ['url' => 'https://def.preview.bl.run/']]),
        BL_API.'/sandboxes/onedrop-project-1-x/previews/port-7681/tokens' => Http::response(['spec' => ['token' => 'tok2']]),
    ]);

    expect($this->blaxel->previewUrl('onedrop-project-1-x', 7681))->toBe('https://def.preview.bl.run/?bl_preview_token=tok2');
})->group('SBX-004');

test('the ssh port gets no https preview', function () {
    expect($this->blaxel->previewUrl('onedrop-project-1-x', config('sandbox.ssh_port')))->toBeNull();

    Http::assertNothingSent();
})->group('SBX-004');

test('pausing freezes the app\'s processes, with a watchdog that thaws them if nobody does, and starting lets them carry on', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/process' => Http::response(blaxelProcess()),
    ]);

    $this->blaxel->pause('onedrop-project-1-x');
    $this->blaxel->start('onedrop-project-1-x');

    $commands = Http::recorded()->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => $request->url() === BL_SBX.'/process')
        ->map(fn (Request $request) => $request['command'])->values();

    expect($commands)->toHaveCount(3)
        ->and($commands[0])->toContain(AppProcesses::FREEZE)
        ->and($commands[1])->toBe('sleep '.BlaxelSandboxProvider::THAW_AFTER_SECONDS.'; '.AppProcesses::THAW)
        ->and($commands[2])->toBe("'pkill' '-CONT' '-u' 'sandbox'");
})->group('SBX-004');

test('a sandbox whose processes cannot be frozen is not copied', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/process' => Http::response(blaxelProcess(1, '', 'pgrep: not found')),
    ]);

    expect(fn () => $this->blaxel->pause('onedrop-project-1-x'))->toThrow(SandboxException::class, 'pgrep: not found');
})->group('SBX-004');

test('deleting a sandbox that is already gone is not an error', function () {
    Http::fake([BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(['error' => 'not found'], 404)]);

    $this->blaxel->destroy('onedrop-project-1-x');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
})->group('SBX-004');

test('missing credentials are explained', function () {
    expect(fn () => (new BlaxelSandboxProvider([...$this->blaxelConfig, 'api_key' => null]))->destroy('onedrop-project-1-x'))
        ->toThrow(SandboxException::class, 'BL_API_KEY and BL_WORKSPACE must be set');
})->group('SBX-004');

test('a sandbox is outdated when it was made from an older build of the image', function (string $label, bool $outdated) {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox(labels: [BlaxelSandboxProvider::IMAGE_LABEL => $label])),
        BL_API.'/images/sandbox/onedrop-sandbox' => Http::response(blaxelImage()),
    ]);

    expect($this->blaxel->isOutdated('onedrop-project-1-x'))->toBe($outdated);
})->with([
    'older build' => ['sandbox/onedrop-sandbox:old1', true],
    'newest build' => ['sandbox/onedrop-sandbox:new2', false],
])->group('SBX-004');

test('a sandbox is not outdated when there is no image to move to', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox(labels: [BlaxelSandboxProvider::IMAGE_LABEL => 'sandbox/onedrop-sandbox:old1'])),
        BL_API.'/images/sandbox/onedrop-sandbox' => Http::response(['error' => 'not found'], 404),
    ]);

    expect($this->blaxel->isOutdated('onedrop-project-1-x'))->toBeFalse();
})->group('SBX-004');

test('copying out a path the sandbox never created copies nothing', function () {
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/process' => Http::response(blaxelProcess(3)),
    ]);

    $this->blaxel->copyOut('onedrop-project-1-x', '/data/storage', sys_get_temp_dir());

    Http::assertSentCount(2);
})->group('SBX-004');

test('copying in uploads an archive to an absolute path and unpacks it', function () {
    Process::fake(['*' => Process::result()]);
    Http::fake([
        BL_API.'/sandboxes/onedrop-project-1-x' => Http::response(blaxelSandbox()),
        BL_SBX.'/filesystem-multipart/initiate/*' => Http::response(['uploadId' => 'up-1']),
        BL_SBX.'/filesystem-multipart/up-1/part*' => Http::response(['etag' => 'e1', 'partNumber' => 1]),
        BL_SBX.'/filesystem-multipart/up-1/complete' => Http::response(['message' => 'ok']),
        BL_SBX.'/process' => Http::response(blaxelProcess()),
    ]);

    $directory = sys_get_temp_dir().'/onedrop-copy-in-'.uniqid();
    mkdir($directory);

    try {
        // tar is faked, so the archive stays empty: one empty read, no parts.
        $this->blaxel->copyIn('onedrop-project-1-x', $directory, '/workspace');
    } finally {
        rmdir($directory);
    }

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/filesystem-multipart/initiate//tmp/onedrop-copy-'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/complete') && $request['parts'] === []);
    Http::assertSent(fn (Request $request) => $request->url() === BL_SBX.'/process' && str_contains($request['command'], "'/workspace'"));
})->group('SBX-004');
