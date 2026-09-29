<?php

use App\Models\Project;
use App\Models\Sandbox;
use App\Models\SshKey;
use App\Models\User;
use App\Sandbox\ProjectBackups;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxInspector;
use App\Sandbox\SandboxSpec;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\WorkspaceFiles;
use App\Sandbox\WorkspaceGit;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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

test('the shell greets each new terminal with a banner, but not nested shells, and has the shell tools', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), ['APP_PROJECT_NAME' => 'Banner Check']));

    try {
        $first = $docker->exec($id, ['bash', '-ic', 'true']);
        expect($first->output)->toContain('\\____/_/ /_/\\___/')->toContain('Banner Check')->toContain('/opt/zap/restart');

        $nested = $docker->exec($id, ['bash', '-ic', 'true'], ['ZAP_BANNER_SHOWN' => '1']);
        expect($nested->output)->not->toContain('Banner Check')
            ->and($docker->exec($id, ['bash', '-ic', 'type z && echo "editor=$EDITOR"'])->output)->toContain('z is a function')->toContain('editor=micro');

        // ls lists through eza, but GNU-only flags still reach GNU ls.
        $listing = fn (string $args) => $docker->exec($id, ['bash', '-ic', "touch /tmp/a /tmp/b && ls {$args} /tmp >/dev/null && echo ok"])->output;
        expect($docker->exec($id, ['bash', '-ic', 'type ls'])->output)->toContain('ls is a function')
            ->and($listing('-la'))->toContain('ok')
            ->and($listing('-ltr'))->toContain('ok');

        foreach (['bat --version', 'rg --version', 'fd --version', 'zoxide --version', 'jq --version', 'btop --version', 'lazygit --version', 'micro -version', 'vim --version', 'ncdu -v'] as $command) {
            expect($docker->exec($id, explode(' ', $command))->successful())->toBeTrue("{$command} failed");
        }
    } finally {
        $docker->destroy($id);
    }
})->group('TAB-001');

test('old shell files carried over by an update give way to the image\'s, and the user\'s own are kept', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3)), ['APP_PROJECT_NAME' => 'Bashrc Check']));
    $sh = fn (string $script) => $docker->exec($id, ['bash', '-c', $script]);

    try {
        // An old sandbox: the whole setup in ~/.bashrc and the image's prompt config in ~/.config.
        $sh('echo "PS1=old" > ~/.bashrc && mkdir -p ~/.config && cp /opt/zap/starship.toml ~/.config/starship.toml');
        $sh(SandboxUpdater::USE_IMAGE_SHELL_SETUP);
        expect($docker->exec($id, ['bash', '-ic', 'true'])->output)->toContain('Bashrc Check')
            ->and($sh('test -e ~/.config/starship.toml')->successful())->toBeFalse()
            ->and(trim($docker->exec($id, ['bash', '-ic', 'echo "$STARSHIP_CONFIG"'])->output))->toEndWith('/opt/zap/starship.toml');

        $sh('echo "alias mine=true" >> ~/.bashrc && echo "add_newline = true" > ~/.config/starship.toml');
        $sh(SandboxUpdater::USE_IMAGE_SHELL_SETUP);
        expect($sh('cat ~/.bashrc')->output)->toContain('alias mine=true')
            ->and($sh('cat ~/.config/starship.toml')->output)->toContain('add_newline = true');
    } finally {
        $docker->destroy($id);
    }
})->group('SBX-002');

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

test('the host proxy sends routed paths to other local servers, but never to the sandbox\'s own', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        $script = 'mkdir -p /workspace/.zap /workspace/public /tmp/rt'
            .' && echo \'<?php echo "app";\' > /workspace/public/index.php'
            .' && echo \'<?php echo "realtime|", $_SERVER["HTTP_HOST"], "|", $_SERVER["REQUEST_URI"];\' > /tmp/rt/index.php'
            .' && printf "#!/usr/bin/env bash\nphp -S 127.0.0.1:8090 /tmp/rt/index.php &\nexec php -S 0.0.0.0:\$PORT /workspace/public/index.php\n" > /workspace/.zap/dev'
            .' && echo \'{"/rt/": 8090, "/term": 7681, "/ssh": 2222, "/loop": 8081}\' > /workspace/.zap/routes.json'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script])->successful())->toBeTrue();

        $get = fn (string $path) => trim($docker->exec($id, ['curl', '-s', '-H', 'Host: my-app.tail1.ts.net', "http://127.0.0.1:8081{$path}"])->output);

        retry(40, fn () => throw_unless(str_starts_with($get('/rt/app/key'), 'realtime'), new RuntimeException('not ready')), 250);

        expect($get('/rt/app/key?protocol=7'))->toBe('realtime|localhost:8090|/rt/app/key?protocol=7')
            ->and($get('/rt'))->toStartWith('realtime')
            ->and($get('/rtx'))->toBe('app')
            ->and($get('/term'))->toBe('app')
            ->and($get('/ssh'))->toBe('app')
            ->and($get('/loop'))->toBe('app')
            ->and($get('/'))->toBe('app');
    } finally {
        $docker->destroy($id);
    }
})->group('RT-001');

test('the sandbox has the PHP extensions realtime servers and queue workers need', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        expect($docker->exec($id, ['php', '-m'])->output)->toContain('pcntl');
    } finally {
        $docker->destroy($id);
    }
})->group('RT-001');

test('the host proxy points localhost links in pages and redirects at the visitor\'s address', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        $script = 'mkdir -p /workspace/.zap /workspace/public'
            .' && echo \'<?php if ($_SERVER["REQUEST_URI"] === "/") { header("Location: http://localhost:8000/contacts"); exit; } echo "<link href=\\"http://localhost:8000/build/app.css\\">";\' > /workspace/public/index.php'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT /workspace/public/index.php\n" > /workspace/.zap/dev'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script])->successful())->toBeTrue();

        $headers = ['-H', 'Host: abc.preview.bl.run', '-H', 'X-Forwarded-Proto: https'];
        $page = retry(40, function () use ($docker, $id, $headers) {
            $out = trim($docker->exec($id, ['curl', '-sf', ...$headers, 'http://127.0.0.1:8081/contacts'])->output);
            throw_unless(str_contains($out, '<link'), new RuntimeException("not ready: {$out}"));

            return $out;
        }, 250);
        $redirect = trim($docker->exec($id, ['curl', '-s', '-o', '/dev/null', '-w', '%{redirect_url}', ...$headers, 'http://127.0.0.1:8081/'])->output);

        expect($page)->toBe('<link href="https://abc.preview.bl.run/build/app.css">')
            ->and($redirect)->toBe('https://abc.preview.bl.run/contacts');
    } finally {
        $docker->destroy($id);
    }
})->group('SBX-001');

test('the host proxy presents same-site Origin and Referer as localhost', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));

    try {
        $script = 'mkdir -p /workspace/.zap /workspace/public'
            // Binary, so the proxy's localhost-link rewriting leaves the echoed headers as the app saw them.
            .' && echo \'<?php header("Content-Type: application/octet-stream"); echo $_SERVER["HTTP_ORIGIN"] ?? "-", "|", $_SERVER["HTTP_REFERER"] ?? "-";\' > /workspace/public/index.php'
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

test('the host proxy records server errors, preview browser errors and the app being down', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));
    $curl = fn (string ...$args) => $docker->exec($id, ['curl', '-s', ...$args])->output;
    $errors = function () use ($docker, $id): array {
        $log = trim($docker->exec($id, ['sh', '-c', 'cat /workspace/.zap/errors.log 2>/dev/null'])->output);

        return $log === '' ? [] : array_map(fn ($line) => json_decode($line, true), explode("\n", $log));
    };

    try {
        $app = <<<'PHP'
            <?php
            if (str_contains($_SERVER['REQUEST_URI'], 'boom')) {
                file_put_contents('php://stderr', "stack: BoomException in routes/web.php\n");
                http_response_code(500);
                echo '<!doctype html><html><head><title>Oops</title><style>body{}</style></head><body><h1>BoomException</h1><p>Something &amp; broke</p><script>var x = 1;</script></body></html>';
                return;
            }
            echo '<!doctype html><html><head><title>App</title></head><body>ok</body></html>';
            PHP;
        $script = 'mkdir -p /workspace/.zap /workspace/public'
            .' && printf %s "$APP" > /workspace/public/index.php'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public /workspace/public/index.php\n" > /workspace/.zap/dev'
            .' && chmod +x /workspace/.zap/dev && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $script], ['APP' => $app])->successful())->toBeTrue();

        retry(40, fn () => throw_unless(
            str_contains($curl('http://127.0.0.1:8081/'), '<body>ok'),
            new RuntimeException('app not up'),
        ), 250);

        // Preview pages get the error reporter first thing in their head; published pages don't.
        expect($curl('http://127.0.0.1:8081/'))->toContain('<head><script src="/__zap/errors.js"></script><title>App</title>')
            ->and($curl('-H', 'Host: my-app.tail1.ts.net', 'http://127.0.0.1:8081/'))->not->toContain('/__zap/errors.js')
            ->and($curl('http://127.0.0.1:8081/__zap/errors.js'))->toContain("navigator.sendBeacon('/__zap/error'");

        // A 5xx is logged with the page's text and the end of the server's output, and its page says so to the app builder.
        expect($curl('http://127.0.0.1:8081/boom?id=1'))->toContain('<script src="/__zap/errors.js" data-status="500"></script>');
        $server = retry(20, function () use ($errors) {
            $found = collect($errors())->firstWhere('k', 'server');
            throw_unless($found, new RuntimeException('not logged yet'));

            return $found;
        }, 250);
        expect($server)->toMatchArray(['s' => 500, 'm' => 'GET', 'p' => '/boom?id=1', 'text' => 'BoomException Something & broke', 'pub' => false])
            ->and($server['log'])->toContain('stack: BoomException in routes/web.php');

        // Browser errors are taken from the preview only, and must look like one.
        $post = fn (string $body, string ...$headers) => trim($curl(...['-o', '/dev/null', '-w', '%{http_code}', '-X', 'POST', ...$headers, '-d', $body, 'http://127.0.0.1:8081/__zap/error']));
        $report = json_encode(['type' => 'error', 'message' => 'x is not defined', 'stack' => 'at App (app.js:1:1)', 'page' => '/dashboard']);
        expect($post($report))->toBe('204')
            ->and($post($report, '-H', 'Host: my-app.tail1.ts.net'))->toBe('400')
            ->and($post('{"type":"server","message":"nope"}'))->toBe('400')
            ->and($post('not json'))->toBe('400');

        // The app going away (here: crashing on start) is logged too.
        $docker->exec($id, ['sh', '-c', 'printf "#!/usr/bin/env bash\nexit 1\n" > /workspace/.zap/dev && /opt/zap/restart']);
        retry(20, fn () => throw_unless(str_contains($curl('http://127.0.0.1:8081/'), 'starting'), new RuntimeException('still up')), 250);

        $logged = retry(20, function () use ($errors) {
            $all = collect($errors());
            throw_unless($all->contains('k', 'down') && $all->contains('k', 'browser'), new RuntimeException('not logged yet'));

            return $all;
        }, 250);
        expect($logged->firstWhere('k', 'browser'))->toMatchArray(['type' => 'error', 'msg' => 'x is not defined', 'stack' => 'at App (app.js:1:1)', 'page' => '/dashboard', 'pub' => false])
            ->and($logged->where('k', 'browser'))->toHaveCount(1)
            ->and($logged->firstWhere('k', 'down'))->toMatchArray(['m' => 'GET', 'p' => '/']);
    } finally {
        $docker->destroy($id);
    }
})->group('ERR-001');

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
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'LogLevel=ERROR', ...$options, 'sandbox@127.0.0.1', 'pwd',
        ]);

        expect(trim($ssh(['-i', $keyFile])->output()))->toBe('/workspace')
            ->and($ssh(['-o', 'PubkeyAuthentication=no'])->failed())->toBeTrue();
    } finally {
        $docker->destroy($id);
        @unlink($keyFile);
        @unlink("{$keyFile}.pub");
    }
})->group('DEVTOOLS-001');

test('a checkpoint is backed up and restored, with its branches, into a fresh sandbox', function () {
    $this->artisan('migrate:fresh');
    Storage::fake('backups');
    config(['sandbox.backup_disk' => 'backups']);

    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $backups = new ProjectBackups($docker);
    $project = Project::factory()->create();
    $old = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));
    $new = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));
    $sandbox = Sandbox::factory()->for($project)->create(['external_id' => $old]);

    try {
        $script = 'cd /workspace && echo "<h1>Timer</h1>" > index.html && echo SECRET=1 > .env'
            .' && echo "Build a timer" | /opt/zap/checkpoint && git branch experiment';
        expect($docker->exec($old, ['bash', '-c', $script])->successful())->toBeTrue()
            ->and($backups->backUp($project))->toBeTrue()
            ->and($backups->backUp($project->fresh()))->toBeFalse();

        $head = $project->fresh()->backup_commit;
        expect($head)->toMatch('/^[0-9a-f]{40}$/');

        $sandbox->update(['external_id' => $new]);
        expect($backups->restore($project, $sandbox))->toBeTrue();

        $git = fn (string $command) => trim($docker->exec($new, ['bash', '-c', "cd /workspace && {$command}"])->output);

        expect($git('cat index.html'))->toBe('<h1>Timer</h1>')
            ->and($git('git rev-parse HEAD'))->toBe($head)
            ->and($git('git log --format=%s'))->toBe('Build a timer')
            ->and($git('git branch --show-current'))->toBe('main')
            ->and($git('git branch --format="%(refname:short)" | sort | tr "\n" " "'))->toBe('experiment main')
            ->and($git('git remote'))->toBe('')
            ->and($git('git status --porcelain'))->toBe('')
            ->and($git('test -e .env && echo yes || echo no'))->toBe('no');
    } finally {
        $docker->destroy($old);
        $docker->destroy($new);
    }
})->group('SBX-006');

test('the git tool commits, lists and restores in a real container', function () {
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    $id = $docker->create(new SandboxSpec('zap-test-'.bin2hex(random_bytes(3))));
    $sandbox = new Sandbox(['external_id' => $id]);
    $git = new WorkspaceGit($docker);
    $user = new User(['name' => 'Dev User', 'email' => 'dev@example.com']);

    try {
        $docker->exec($id, ['bash', '-c', 'cd /workspace && echo one > a.txt && echo "Build a timer" | /opt/zap/checkpoint && echo two > a.txt']);

        expect($git->status($sandbox))->toMatchArray(['branch' => 'main', 'changes' => [['path' => 'a.txt', 'status' => 'M']]]);

        $git->commit($sandbox, 'Second', $user);
        $commits = $git->log($sandbox);

        expect(collect($commits)->map(fn (array $commit) => [$commit['subject'], $commit['agent']])->all())->toBe([['Second', false], ['Build a timer', true]]);

        $git->restore($sandbox, $commits[1]['sha'], $user);

        expect(trim($docker->exec($id, ['cat', '/workspace/a.txt'])->output))->toBe('one')
            ->and($git->log($sandbox)[0]['subject'])->toStartWith('Restore "Build a timer"');
    } finally {
        $docker->destroy($id);
    }
})->group('GIT-003');
