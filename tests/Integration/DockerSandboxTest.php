<?php

use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SshKey;
use App\Models\User;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxInspector;
use App\Sandbox\SandboxSpec;
use App\Sandbox\WorkspaceFiles;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
 * Talks to real Docker. Opt in with: RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration
 * Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }
});

test('a real container serves the placeholder app on its preview url', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), ['APP_PROJECT_NAME' => 'Integration Check']));

    try {
        $url = $docker->previewUrl($id, 8000);
        expect($url)->toStartWith('http://127.0.0.1:');

        $body = retry(20, fn () => Http::timeout(2)->get($url)->throw()->body(), 250);
        expect($body)->toContain('Integration Check')->toContain('Sandbox is running');

        $env = $docker->exec($id, ['printenv', 'APP_PROJECT_NAME']);
        expect(trim($env->output))->toBe('Integration Check');
    } finally {
        $docker->destroy($id);
    }
})->group('SBX-001');

test('the preview switches to the app dev server after /opt/zap/restart', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        $url = $docker->previewUrl($id, 8000);
        expect(retry(20, fn () => Http::timeout(2)->get($url)->throw()->body(), 250))->toContain('Sandbox is running');

        $script = 'mkdir -p /workspace/.zap /workspace/public && echo "hello from the app" > /workspace/public/index.html'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public\n" > /workspace/.zap/dev'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script])->successful())->toBeTrue();

        $body = retry(40, function () use ($url) {
            $body = Http::timeout(2)->get($url)->throw()->body();
            throw_unless(str_contains($body, 'hello from the app'), new RuntimeException('still placeholder'));

            return $body;
        }, 250);

        expect($body)->toContain('hello from the app');
    } finally {
        $docker->destroy($id);
    }
})->group('AGT-001');

test('the web terminal answers on the shell port', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), shellPort: 7681));

    try {
        $url = $docker->previewUrl($id, 7681);
        expect($url)->toStartWith('http://127.0.0.1:');

        $body = retry(20, fn () => Http::timeout(2)->get($url)->throw()->body(), 250);
        expect($body)->toContain('ttyd');
    } finally {
        $docker->destroy($id);
    }
})->group('TAB-001');

test('the host proxy rewrites unknown hostnames to localhost', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        $script = 'mkdir -p /workspace/.zap /workspace/public'
            .' && echo \'<?php echo $_SERVER["HTTP_HOST"], "|", $_SERVER["HTTP_X_FORWARDED_HOST"] ?? "";\' > /workspace/public/index.php'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public\n" > /workspace/.zap/dev'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script])->successful())->toBeTrue();

        $body = retry(40, function () use ($docker, $id) {
            $out = trim($docker->exec($id, ['curl', '-sf', '-H', 'Host: my-app-1.tail123.ts.net', 'http://127.0.0.1:8081/'])->output);
            throw_unless(str_contains($out, '|'), new RuntimeException("not ready: {$out}"));

            return $out;
        }, 250);

        expect($body)->toBe('localhost:8000|my-app-1.tail123.ts.net');
    } finally {
        $docker->destroy($id);
    }
})->group('PUB-001');

test('the host proxy presents same-site Origin and Referer as localhost', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        $script = 'mkdir -p /workspace/.zap /workspace/public'
            .' && echo \'<?php echo $_SERVER["HTTP_ORIGIN"] ?? "-", "|", $_SERVER["HTTP_REFERER"] ?? "-";\' > /workspace/public/index.php'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public\n" > /workspace/.zap/dev'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script])->successful())->toBeTrue();

        $body = retry(40, function () use ($docker, $id) {
            $out = trim($docker->exec($id, ['curl', '-sf',
                '-H', 'Host: 127.0.0.1:32800',
                '-H', 'Origin: http://127.0.0.1:32800',
                '-H', 'Referer: http://127.0.0.1:32800/contacts',
                'http://127.0.0.1:8081/'])->output);
            throw_unless(str_contains($out, '|'), new RuntimeException("not ready: {$out}"));

            return $out;
        }, 250);

        expect($body)->toBe('http://localhost:8000|http://localhost:8000/contacts');
    } finally {
        $docker->destroy($id);
    }
})->group('PUB-001');

test('stop-agent ends the agent run and everything it started', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        // A stand-in agent that starts a long-running child, like `npm run build` would.
        $fake = 'mkdir -p /tmp/fake && printf "#!/bin/sh\nsleep 600 &\nsleep 601\n" > /tmp/fake/opencode && chmod +x /tmp/fake/opencode';
        expect($docker->exec($id, ['bash', '-c', $fake])->successful())->toBeTrue();

        $docker->exec($id, ['bash', '-c', 'PATH=/tmp/fake:$PATH APP_PROMPT=x APP_MODEL=x APP_EVENTS_URL=http://127.0.0.1:9/none APP_EVENTS_TOKEN=x node /opt/zap/forwarder.mjs >/dev/null 2>&1'], detach: true);

        retry(20, fn () => throw_unless(
            str_contains($docker->exec($id, ['bash', '-c', 'pgrep -f "sleep 60[01]" | wc -l'])->output, '2'),
            new RuntimeException('agent not started'),
        ), 250);

        expect($docker->exec($id, ['/opt/zap/stop-agent'])->successful())->toBeTrue();

        $left = retry(20, function () use ($docker, $id) {
            $count = trim($docker->exec($id, ['bash', '-c', 'pgrep -f "sleep 60[01]|[f]orwarder.mjs" | wc -l'])->output);
            throw_unless($count === '0', new RuntimeException("still running: {$count}"));

            return $count;
        }, 250);

        expect($left)->toBe('0')
            ->and($docker->exec($id, ['test', '-f', '/tmp/zap-agent.pid'])->successful())->toBeFalse();
    } finally {
        $docker->destroy($id);
    }
})->group('AGT-003');

test('the host proxy records requests and resource samples for monitoring', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), ['APP_METRICS_INTERVAL' => '1000']));
    $lines = function () use ($docker, $id): array {
        $log = trim($docker->exec($id, ['cat', '/workspace/.zap/access.log'])->output);

        return $log === '' ? [] : array_map(fn ($line) => json_decode($line, true), explode("\n", $log));
    };

    try {
        $script = 'mkdir -p /workspace/.zap /workspace/public'
            .' && echo \'<?php http_response_code(str_contains($_SERVER["REQUEST_URI"], "missing") ? 404 : 200); echo "ok";\' > /workspace/public/index.php'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public /workspace/public/index.php\n" > /workspace/.zap/dev'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script])->successful())->toBeTrue();

        retry(40, fn () => throw_unless(
            trim($docker->exec($id, ['curl', '-s', 'http://127.0.0.1:8081/'])->output) === 'ok',
            new RuntimeException('app not up'),
        ), 250);
        usleep(300_000);
        $before = count($lines());

        // Vite internals aren't counted.
        $docker->exec($id, ['curl', '-s', '-o', '/dev/null', 'http://127.0.0.1:8081/@vite/client']);
        usleep(300_000);
        expect(count($lines()))->toBe($before);

        // A published-address request is logged with its status, visitor IP and origin.
        $docker->exec($id, ['curl', '-s', '-o', '/dev/null', '-H', 'Host: my-app.tail1.ts.net', '-H', 'X-Forwarded-For: 203.0.113.7', '-H', 'Referer: https://news.example.com/post', '-H', 'User-Agent: TestBrowser/1.0', '-H', 'CF-IPCountry: ca', 'http://127.0.0.1:8081/missing?x=1']);
        $latest = retry(20, function () use ($lines, $before) {
            $all = $lines();
            throw_unless(count($all) > $before, new RuntimeException('not logged yet'));

            return end($all);
        }, 250);

        // With what Growth needs: page (no query string), referring site, browser and country.
        expect($latest)->toMatchArray(['s' => 404, 'ip' => '203.0.113.7', 'pub' => true, 'm' => 'GET', 'p' => '/missing', 'r' => 'news.example.com', 'ua' => 'TestBrowser/1.0', 'c' => 'CA'])
            ->and($latest['d'])->toBeInt()
            ->and(collect($lines())->where('s', 200)->every(fn ($line) => $line['pub'] === false))->toBeTrue();

        // CPU (share of the 2-CPU limit) and memory samples arrive on the configured interval.
        $sample = retry(20, function () use ($docker, $id) {
            $log = trim($docker->exec($id, ['cat', '/workspace/.zap/metrics.log'])->output);
            throw_unless($log !== '', new RuntimeException('no samples yet'));

            return json_decode(strtok($log, "\n"), true);
        }, 500);

        expect($sample['cpu'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1)
            ->and($sample['mem'])->toBeGreaterThan(0)
            ->and($sample['memMax'])->toBe(2 * 1024 * 1024 * 1024);
    } finally {
        $docker->destroy($id);
    }
})->group('MON-001', 'GROW-001');

test('the host proxy records custom analytics events without passing them to the app', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));
    $post = fn (string $body, array $headers = []) => trim($docker->exec($id, [
        'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-X', 'POST', ...$headers, '-d', $body, 'http://127.0.0.1:8081/__zap/event',
    ])->output);

    try {
        retry(40, fn () => throw_unless(
            $docker->exec($id, ['curl', '-sf', '-o', '/dev/null', 'http://127.0.0.1:8081/'])->successful(),
            new RuntimeException('proxy not up'),
        ), 250);

        expect($post('{"name":"project_created","props":{"template":"blank","count":2,"Bad Key":"x","nested":{"a":1}}}', ['-H', 'Host: my-app.tail1.ts.net', '-H', 'X-Forwarded-For: 203.0.113.7']))->toBe('204')
            ->and($post('{"name":"Not Valid"}'))->toBe('400')
            ->and($post('not json'))->toBe('400')
            ->and($post(json_encode(['name' => 'big', 'props' => ['a' => str_repeat('x', 5000)]])))->toBe('400');

        $log = retry(20, function () use ($docker, $id) {
            $log = trim($docker->exec($id, ['cat', '/workspace/.zap/events.log'])->output);
            throw_unless($log !== '', new RuntimeException('not logged yet'));

            return array_map(fn ($line) => json_decode($line, true), explode("\n", $log));
        }, 250);

        expect($log)->toHaveCount(1)
            ->and($log[0])->toMatchArray(['n' => 'project_created', 'props' => ['template' => 'blank', 'count' => 2], 'ip' => '203.0.113.7', 'pub' => true])
            ->and(trim($docker->exec($id, ['sh', '-c', 'grep -c __zap /workspace/.zap/access.log || true'])->output))->toBe('0');
    } finally {
        $docker->destroy($id);
    }
})->group('GROW-002');

test('files can be created, uploaded and downloaded as a zip in a real container', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));
    $sandbox = new Sandbox(['external_id' => $id]);
    $files = new WorkspaceFiles($docker);

    try {
        expect($files->create($sandbox, 'notes/todo.md', 'file'))->toBeTrue()
            ->and($files->create($sandbox, 'notes/todo.md', 'file'))->toBeFalse()
            ->and($files->create($sandbox, 'empty/dir', 'dir'))->toBeTrue();

        $binary = random_bytes(100_000)."\0end";
        $files->upload($sandbox, 'site/img/logo.bin', $binary);
        $docker->exec($id, ['sh', '-c', 'mkdir -p /workspace/node_modules/x && echo skip > /workspace/node_modules/x/y']);

        $path = tempnam(sys_get_temp_dir(), 'zap-zip');
        file_put_contents($path, $files->zip($sandbox));
        $zip = new ZipArchive;
        $zip->open($path);

        expect($zip->getFromName('site/img/logo.bin'))->toBe($binary)
            ->and($zip->getFromName('notes/todo.md'))->toBe('')
            ->and($zip->locateName('empty/dir/'))->not->toBeFalse()
            ->and($zip->locateName('node_modules/x/y'))->toBeFalse();

        $zip->close();
        unlink($path);
    } finally {
        $docker->destroy($id);
    }
})->group('FILE-003');

test('developer tools read ports, usage and storage, and SSH takes the owner\'s key', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), shellPort: 7681, proxyPort: 8081, sshPort: 2222));
    $keyFile = sys_get_temp_dir().'/zap-test-key-'.bin2hex(random_bytes(3));

    try {
        $sandbox = new Sandbox(['external_id' => $id]);
        $inspector = new SandboxInspector($docker);

        $ports = retry(20, function () use ($inspector, $sandbox) {
            $ports = collect($inspector->ports($sandbox));
            throw_unless($ports->contains('port', 2222) && $ports->contains('port', 8000), new RuntimeException('Not listening yet'));

            return $ports;
        }, 250);
        expect($ports->firstWhere('port', 7681))->toMatchArray(['process' => 'ttyd', 'role' => 'shell'])
            ->and($ports->firstWhere('port', 2222)['role'])->toBe('ssh');

        $usage = $inspector->usage($sandbox);
        expect($usage['cpus'])->toBeGreaterThan(0)
            ->and($usage['memory'])->toBeGreaterThan(0)
            ->and($usage['memory_limit'])->toBeGreaterThanOrEqual($usage['memory']);

        $storage = $inspector->storage($sandbox);
        expect($storage['disk_total'])->toBeGreaterThan(0)->and($storage['workspace'])->toBeInt();

        // The owner's key goes into authorized_keys; sshd then accepts it (and nothing else).
        Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $keyFile])->throw();
        $user = new User;
        $user->setRelation('sshKeys', collect([new SshKey(['name' => 'Test key', 'public_key' => trim(file_get_contents("{$keyFile}.pub"))])]));
        $sandbox->setRelation('project', (new Project)->setRelation('user', $user));

        expect((new WorkspaceSsh($docker))->sync($sandbox))->toBeTrue();

        $port = parse_url($docker->previewUrl($id, 2222), PHP_URL_PORT);
        $ssh = fn (array $options) => Process::timeout(20)->run([
            'ssh', '-p', (string) $port, '-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'BatchMode=yes', '-o', 'LogLevel=ERROR', ...$options, 'sandbox@127.0.0.1', 'pwd',
        ]);

        expect(trim($ssh(['-i', $keyFile])->output()))->toBe('/workspace')
            ->and($ssh(['-o', 'PubkeyAuthentication=no'])->failed())->toBeTrue();
    } finally {
        $docker->destroy($id);
        @unlink($keyFile);
        @unlink("{$keyFile}.pub");
    }
})->group('DEVTOOLS-001');
