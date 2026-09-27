<?php

use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxSpec;
use Illuminate\Support\Facades\Http;
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
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), ['ZAP_PROJECT_NAME' => 'Integration Check']));

    try {
        $url = $docker->previewUrl($id, 8000);
        expect($url)->toStartWith('http://127.0.0.1:');

        $body = retry(20, fn () => Http::timeout(2)->get($url)->throw()->body(), 250);
        expect($body)->toContain('Integration Check')->toContain('Sandbox is running');

        $env = $docker->exec($id, ['printenv', 'ZAP_PROJECT_NAME']);
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
