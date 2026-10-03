<?php

use Illuminate\Support\Facades\File;
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

test('an app packed in its sandbox runs hosted from the release, with its data copied onto the volume once', function () {
    $image = config('sandbox.providers.docker.image');
    $script = base_path('docker/sandbox/hosting');
    $work = sys_get_temp_dir().'/onedrop-hosting-'.bin2hex(random_bytes(3));
    $source = 'onedrop-hosting-src-'.bin2hex(random_bytes(3));
    $hosted = 'onedrop-hosting-app-'.bin2hex(random_bytes(3));
    // A named volume, like Fly's: a bind mount on macOS doesn't keep file owners.
    $volume = "{$hosted}-data";
    File::ensureDirectoryExists($work);
    $docker = fn (array $command) => Process::timeout(120)->run(['docker', ...$command])->throw();

    try {
        // The sandbox: a small server counting hits in .onedrop/data, a declared data folder, and App Storage.
        // The current tools, as SandboxTools copies them into running sandboxes.
        $docker(['run', '-d', '--name', $source, '-v', "{$script}:/opt/onedrop/hosting:ro", '-v', base_path('docker/sandbox/db.php').':/opt/onedrop/db.php:ro', $image, 'sleep', '600']);
        $setup = <<<'BASH'
            set -e
            cd /workspace
            mkdir -p .onedrop/data uploads /data/storage/photos
            echo 'const fs=require("fs");require("http").createServer((q,r)=>{const n=+fs.readFileSync(".onedrop/data/count")+1;fs.writeFileSync(".onedrop/data/count",String(n));r.end(`hits ${n} ${fs.readFileSync("uploads/a.txt","utf8").trim()} ${fs.readdirSync(process.env.APP_STORAGE_DIR)}`)}).listen(process.env.PORT,"0.0.0.0")' > server.js
            printf '#!/usr/bin/env bash\nexec node server.js\n' > .onedrop/start && chmod +x .onedrop/start
            printf '#!/usr/bin/env bash\necho built > built.txt\n' > .onedrop/build && chmod +x .onedrop/build
            echo '{"data": ["uploads"], "services": ["redis", "nonsense"]}' > .onedrop/host.json
            echo 41 > .onedrop/data/count && echo upload > uploads/a.txt && echo jpg > /data/storage/photos/p.jpg
            mkdir -p database && sqlite3 database/database.sqlite "create table doses (id integer primary key, mg int); insert into doses (mg) values (5)"
            BASH;
        $docker(['exec', $source, 'bash', '-c', $setup]);

        $manifest = json_decode($docker(['exec', $source, '/opt/onedrop/hosting', 'inspect'])->output(), true);
        expect($manifest)->toBe(['static' => null, 'services' => ['redis'], 'data' => ['.onedrop/data', 'database/database.sqlite', 'uploads'], 'sqlite' => ['database/database.sqlite'], 'storage' => true]);

        $packed = $docker(['exec', $source, '/opt/onedrop/hosting', 'pack', '/tmp/out', 'seed'])->output();
        expect($packed)->toMatch('/^release \d+$/m')->toMatch('/^seed \d+$/m');
        $docker(['cp', "{$source}:/tmp/out/release.tar.gz", "{$work}/release.tar.gz"]);
        $docker(['cp', "{$source}:/tmp/out/seed.tar.gz", "{$work}/seed.tar.gz"]);

        $release = Process::run(['tar', '-tzf', "{$work}/release.tar.gz"])->throw()->output();
        expect($release)->toContain('workspace/built.txt')
            ->toContain('opt/onedrop-hosting/run')
            ->toContain('opt/onedrop-hosting/db.php')
            ->not->toContain('workspace/uploads/')
            ->not->toContain('workspace/.onedrop/data/');

        // The hosted machine: the release over the base image, run as root with an empty volume at /data.
        $boot = 'tar -xzf /release/release.tar.gz -C / --numeric-owner && (cd /release && python3 -m http.server 9999 >/dev/null 2>&1 &) && sleep 1 && exec /opt/onedrop-hosting/run run';
        $docker(['run', '-d', '--name', $hosted, '--user', 'root', '-p', '127.0.0.1::8081', '-v', "{$volume}:/data", '-v', "{$work}:/release:ro",
            '-e', 'ONEDROP_VOLUME=1', '-e', 'ONEDROP_DATA=.onedrop/data:database/database.sqlite:uploads', '-e', 'ONEDROP_SEED_URL=http://127.0.0.1:9999/seed.tar.gz',
            '--entrypoint', 'bash', $image, '-c', $boot]);
        $get = function () use ($docker, $hosted): ?string {
            // The host port is picked again on each start.
            $port = trim(explode(':', trim($docker(['port', $hosted, '8081'])->output()))[1] ?? '');

            foreach (range(1, 30) as $_) {
                try {
                    $response = Http::withHeaders(['Host' => 'app.fly.dev'])->timeout(2)->get("http://127.0.0.1:{$port}/");

                    if ($response->successful()) {
                        return $response->body();
                    }
                } catch (Throwable) {
                }

                usleep(500_000);
            }

            return null;
        };

        expect($get())->toBe('hits 42 upload photos');

        // Tools → Database on the hosted app (HOST-007): the same command WorkspaceDatabase sends, run as root like Fly's.
        $tool = fn (array $request) => json_decode($docker(['exec', '--user', 'root', $hosted, '/bin/sh', '-c',
            'if [ "$(id -u)" = 0 ]; then exec runuser -u sandbox -- "$@"; fi; exec "$@"', 'sh', 'env', 'ONEDROP_HOSTED=1',
            'APP_DB_REQUEST='.json_encode($request), 'sh', '-c', 'f=/opt/onedrop-hosting/db.php; [ -f "$f" ] || f=/opt/onedrop/db.php; exec php "$f"'])->output(), true);
        expect($tool(['op' => 'connections'])['data'][0]['id'] ?? null)->toBe('sqlite:database/database.sqlite')
            ->and($tool(['op' => 'query', 'connection' => 'sqlite:database/database.sqlite', 'sql' => 'insert into doses (mg) values (10)'])['ok'])->toBeTrue()
            ->and($docker(['exec', $hosted, 'stat', '-c', '%U', '/data/workspace/database/database.sqlite'])->output())->toContain('sandbox');

        // A restart keeps the volume's data and doesn't copy the sandbox's again.
        $docker(['restart', $hosted]);
        expect($get())->toBe('hits 43 upload photos')
            ->and($docker(['exec', $hosted, 'stat', '-c', '%U', '/data/workspace/.onedrop/data/count'])->output())->toContain('sandbox');
    } finally {
        Process::run(['docker', 'rm', '-f', $source, $hosted]);
        Process::run(['docker', 'volume', 'rm', '-f', $volume]);
        File::deleteDirectory($work);
    }
})->group('HOST-001', 'HOST-002');

test('a Vite app with no host.json is built and packed as a front end', function () {
    $image = config('sandbox.providers.docker.image');
    $name = 'onedrop-hosting-vite-'.bin2hex(random_bytes(3));
    $docker = fn (array $command) => Process::timeout(120)->run(['docker', ...$command])->throw();

    try {
        $docker(['run', '-d', '--name', $name, '-v', base_path('docker/sandbox/hosting').':/opt/onedrop/hosting:ro', $image, 'sleep', '600']);
        // Its build script stands in for `vite build`, which needs the network to install.
        $setup = <<<'BASH'
            set -e
            cd /workspace
            echo '{"scripts": {"dev": "vite", "build": "mkdir -p out && cp index.html out/"}, "devDependencies": {"vite": "^8.0.0"}}' > package.json
            echo "export default { build: { outDir: 'out' } }" > vite.config.ts
            echo '<h1>Counter</h1>' > index.html
            BASH;
        $docker(['exec', $name, 'bash', '-c', $setup]);

        expect(json_decode($docker(['exec', $name, '/opt/onedrop/hosting', 'inspect'])->output(), true))
            ->toMatchArray(['static' => 'out', 'guessed' => true]);
        expect($docker(['exec', $name, '/opt/onedrop/hosting', 'pack', '/tmp/out'])->output())->toMatch('/^static \d+$/m');

        // Anything that runs a server is left alone.
        $docker(['exec', $name, 'bash', '-c', 'cd /workspace && echo "require(\"http\")" > server.js']);
        expect(json_decode($docker(['exec', $name, '/opt/onedrop/hosting', 'inspect'])->output(), true)['static'])->toBeNull();
    } finally {
        Process::run(['docker', 'rm', '-f', $name]);
    }
})->group('HOST-001');

test('packages are installed again from the lockfile, and an app that keeps stopping says why', function () {
    $image = config('sandbox.providers.docker.image');
    $name = 'onedrop-hosting-deps-'.bin2hex(random_bytes(3));
    $docker = fn (array $command) => Process::timeout(120)->run(['docker', ...$command])->throw();

    try {
        $docker(['run', '-d', '--name', $name, '-v', base_path('docker/sandbox/hosting').':/opt/onedrop/hosting:ro', $image, 'sleep', '600']);
        $setup = <<<'BASH'
            set -e
            cd /workspace
            echo '{"name": "app", "version": "1.0.0"}' > package.json
            npm install --no-audit --no-fund >/dev/null
            mkdir -p node_modules/stale .onedrop
            printf '#!/usr/bin/env bash\necho "Error: Cannot find native binding" >&2\nexit 1\n' > .onedrop/start
            chmod +x .onedrop/start
            BASH;
        $docker(['exec', $name, 'bash', '-c', $setup]);

        expect($docker(['exec', $name, '/opt/onedrop/hosting', 'deps'])->output())->toContain('again for')
            ->and($docker(['exec', $name, 'bash', '-c', 'test -d /workspace/node_modules/stale && echo kept || echo gone'])->output())->toContain('gone');

        $docker(['exec', '-d', $name, 'timeout', '30', '/opt/onedrop/hosting', 'app']);
        $answer = null;

        foreach (range(1, 20) as $_) {
            $answer = Process::run(['docker', 'exec', $name, 'curl', '-si', '-H', 'Host: app.fly.dev', 'http://127.0.0.1:8081/'])->output();

            if (str_contains($answer, 'x-onedrop-app: crashed')) {
                break;
            }

            usleep(500_000);
        }

        expect($answer)->toContain('503')->toContain('Cannot find native binding');
    } finally {
        Process::run(['docker', 'rm', '-f', $name]);
    }
})->group('HOST-001');
