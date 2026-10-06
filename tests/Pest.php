<?php

use App\Models\Organization;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxTools;
use App\Sandbox\WorkspaceAuth;
use App\Sandbox\WorkspaceDatabase;
use App\Sandbox\WorkspaceSecrets;
use App\Sandbox\WorkspaceStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

// Browser tests use built assets (`npm run build`), even while `composer run dev` is running.
pest()->beforeEach(fn () => Vite::useHotFile(storage_path('framework/testing-no-vite-hot')))
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A temporary workspace with a Laravel-style SQLite database, cleaned up after the test.
 * Tables: users (id, name, email, active, avatar blob), notes (no primary key), and a view.
 */
function databaseWorkspace(): string
{
    $root = sys_get_temp_dir().'/onedrop-db-'.bin2hex(random_bytes(4));
    mkdir($root.'/database', recursive: true);
    file_put_contents($root.'/.env', "APP_NAME=Demo\nDB_CONNECTION=sqlite\n");

    $pdo = new PDO('sqlite:'.$root.'/database/database.sqlite');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT, active BOOLEAN DEFAULT 1, avatar BLOB)');
    $pdo->exec('CREATE TABLE notes (body TEXT)');
    $pdo->exec('CREATE VIEW user_names AS SELECT name FROM users');
    $pdo->exec("INSERT INTO users (name, email, avatar) VALUES ('Ann', 'ann@example.com', X'00FF'), ('Bob', 'bob@example.com', NULL), ('Cy_1', '100%@example.com', NULL)");
    $pdo->exec("INSERT INTO notes VALUES ('hello')");

    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    return $root;
}

/**
 * Run the sandbox's database tool (docker/sandbox/db.php) locally against a workspace.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function runDatabaseTool(string $workspace, array $request, array $env = []): array
{
    return json_decode(runDatabaseScript($workspace, json_encode($request), $env), true);
}

/**
 * @param  array<string, string>  $env
 */
function runDatabaseScript(string $workspace, string $request, array $env = []): string
{
    // The machine's own Docker isn't the sandbox's: tests that need containers pass a fake one.
    return Process::env(['APP_WORKSPACE' => $workspace, 'APP_DB_REQUEST' => $request, 'ONEDROP_DOCKER_BIN' => 'false', ...$env])
        ->run([PHP_BINARY, base_path('docker/sandbox/db.php')])
        ->throw()
        ->output();
}

/**
 * A fake provider whose sandbox runs the real database, Users & Auth and Secrets tools against a local workspace.
 */
function fakeDatabaseSandbox(string $workspace): FakeSandboxProvider
{
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command, array $env) => match ($command) {
        ['php', WorkspaceDatabase::SCRIPT] => new ExecResult(0, runDatabaseScript($workspace, $env['APP_DB_REQUEST'])),
        ['php', WorkspaceAuth::SCRIPT] => new ExecResult(0, runAuthScript($workspace, $env['APP_AUTH_REQUEST'])),
        ['php', WorkspaceSecrets::SCRIPT] => new ExecResult(0, runSecretsScript($workspace, $env['APP_SECRETS_REQUEST'])),
        default => new ExecResult(0, ''),
    };

    return $provider;
}

/**
 * databaseWorkspace() with sign-in set up the way docker/sandbox/guides/auth.md describes:
 * users get created_at and last_login_at, and .onedrop/auth.json describes it.
 *
 * @param  array<string, mixed>  $manifest  merged over the default manifest
 * @param  string|null  $root  an existing databaseWorkspace() to set up, instead of a new one
 */
function authWorkspace(array $manifest = [], ?string $root = null): string
{
    $root ??= databaseWorkspace();

    $pdo = new PDO('sqlite:'.$root.'/database/database.sqlite');
    $pdo->exec('ALTER TABLE users ADD COLUMN created_at TEXT');
    $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at TEXT');
    $pdo->exec("UPDATE users SET created_at = '2026-09-0' || id || ' 10:00:00'");
    $pdo->exec("UPDATE users SET last_login_at = '2026-09-20 08:00:00' WHERE name = 'Ann'");

    mkdir($root.'/.onedrop');
    file_put_contents($root.'/.onedrop/auth.json', json_encode([
        'version' => 1,
        'library' => 'Laravel Fortify + Socialite',
        'methods' => ['password', 'google'],
        ...$manifest,
    ]));

    return $root;
}

/**
 * Run the sandbox's Users & Auth tool (docker/sandbox/auth.php) locally against a workspace.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function withUsersHelper(string $workspace): string
{
    $pdo = new PDO('sqlite:'.$workspace.'/database/database.sqlite');
    $pdo->exec('ALTER TABLE users ADD COLUMN password TEXT');

    // Stands in for the helper the agent writes (guides/auth.md): JSON on stdin, a banner, then one JSON line.
    file_put_contents($workspace.'/.onedrop/users', '#!'.PHP_BINARY.<<<'PHP'

        <?php
        $request = json_decode(stream_get_contents(STDIN), true);
        $pdo = new PDO('sqlite:database/database.sqlite');
        echo "Booting app...\n";

        if ($request['op'] === 'create') {
            $taken = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $taken->execute([$request['email']]);

            if ($taken->fetchColumn() > 0) {
                exit(json_encode(['ok' => false, 'error' => 'That email already has an account.'])."\n");
            }

            $pdo->prepare("INSERT INTO users (name, email, password, created_at) VALUES (?, ?, ?, '2026-09-10 09:00:00')")
                ->execute([$request['name'], $request['email'], password_hash($request['password'], PASSWORD_BCRYPT)]);
            exit(json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()])."\n");
        }

        if ($request['op'] === 'sign-in-link') {
            exit(json_encode(['ok' => true, 'path' => '/auth/onedrop-sign-in?token=one-time-'.$request['id']])."\n");
        }

        if ($request['op'] === 'sign-out') {
            file_put_contents('.onedrop/signed-out', $request['id']."\n", FILE_APPEND);
            exit(json_encode(['ok' => true])."\n");
        }

        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($request['password'], PASSWORD_BCRYPT), $request['id']]);
        echo json_encode(['ok' => true]), "\n";
        PHP);
    chmod($workspace.'/.onedrop/users', 0755);

    return $workspace;
}

/**
 * Give an authWorkspace() the account-control and role columns the guide asks for (Ann is the admin).
 */
function withAccountControls(string $workspace): string
{
    $pdo = new PDO('sqlite:'.$workspace.'/database/database.sqlite');
    $pdo->exec('ALTER TABLE users ADD COLUMN disabled_at TEXT');
    $pdo->exec('ALTER TABLE users ADD COLUMN password_change_required BOOLEAN NOT NULL DEFAULT 0');
    $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'member'");
    $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1");

    return $workspace;
}

/**
 * Ids the fake users helper was asked to sign out, in order.
 *
 * @return list<string>
 */
function signedOutUsers(string $workspace): array
{
    return file("{$workspace}/.onedrop/signed-out", FILE_IGNORE_NEW_LINES) ?: [];
}

/**
 * The app's stored password hash for a user, from an authWorkspace() with withUsersHelper().
 */
function workspacePasswordHash(string $workspace, int $id): ?string
{
    $statement = (new PDO('sqlite:'.$workspace.'/database/database.sqlite'))->prepare('SELECT password FROM users WHERE id = ?');
    $statement->execute([$id]);

    return $statement->fetchColumn() ?: null;
}

function runAuthTool(string $workspace, array $request): array
{
    return json_decode(runAuthScript($workspace, json_encode($request)), true);
}

function runAuthScript(string $workspace, string $request): string
{
    return Process::env(['APP_WORKSPACE' => $workspace, 'APP_AUTH_REQUEST' => $request, 'ONEDROP_DOCKER_BIN' => 'false'])
        ->run([PHP_BINARY, base_path('docker/sandbox/auth.php')])
        ->throw()
        ->output();
}

/**
 * Run the sandbox's Secrets tool (docker/sandbox/secrets.php) locally against a workspace.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function runSecretsTool(string $workspace, array $request): array
{
    return json_decode(runSecretsScript($workspace, json_encode($request)), true);
}

function runSecretsScript(string $workspace, string $request): string
{
    return Process::env(['APP_WORKSPACE' => $workspace, 'APP_SECRETS_REQUEST' => $request])
        ->run([PHP_BINARY, base_path('docker/sandbox/secrets.php')])
        ->throw()
        ->output();
}

/**
 * A temporary App Storage folder (what $APP_STORAGE_DIR is in a sandbox), cleaned up after the test.
 */
function storageRoot(): string
{
    $root = sys_get_temp_dir().'/onedrop-storage-'.bin2hex(random_bytes(4));
    mkdir($root);
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    return $root;
}

/**
 * Run the sandbox's App Storage tool (docker/sandbox/storage.php) locally against a storage folder.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function runStorageTool(string $root, array $request): array
{
    return json_decode(runStorageScript($root, json_encode($request)), true);
}

function runStorageScript(string $root, string $request): string
{
    return Process::env(['APP_STORAGE_DIR' => $root, 'APP_STORAGE_REQUEST' => $request])
        ->run([PHP_BINARY, base_path('docker/sandbox/storage.php')])
        ->throw()
        ->output();
}

/**
 * A fake provider whose sandbox runs the real App Storage tool against a local storage folder.
 */
function fakeStorageSandbox(string $root): FakeSandboxProvider
{
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command, array $env) => $command === ['php', WorkspaceStorage::SCRIPT]
        ? new ExecResult(0, runStorageScript($root, $env['APP_STORAGE_REQUEST']))
        : new ExecResult(0, '');

    return $provider;
}

/**
 * An address in the install's one organization (ORG-002), e.g. orgPath('/groups') is `/o/onedrop/groups`.
 */
function orgPath(string $path = ''): string
{
    return '/o/'.Organization::install()->slug.$path;
}

/**
 * A made-up Stripe secret key, put together from two pieces so GitHub's push protection doesn't take it for a real one.
 */
function fakeStripeKey(): string
{
    return 'sk_'.'live_51Hx9aKJ2b3C4d5E6f7G8h9I0j';
}

/**
 * A fake sandbox that answers the hosting tool: `inspect` with $manifest, `pack` with $packed.
 */
function hostingSandbox(): FakeSandboxProvider
{
    $provider = new class extends FakeSandboxProvider
    {
        /** @var array<string, mixed> */
        public array $manifest = ['static' => null, 'services' => [], 'data' => ['.onedrop/data'], 'storage' => false];

        public string $packed = "release 2097152\nseed 1024";

        public ?string $packError = null;

        /** The workspace's commit, and what `git log` says since the live deploy (count, then "sha<TAB>subject" lines). */
        public string $head = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        public string $changes = '';

        /** Lines of the preview's errors.log, for the check after a turn. */
        public string $previewErrors = '';

        public function exec(string $id, array $command, array $env = [], bool $detach = false, bool $root = false): ExecResult
        {
            $this->executed[] = ['id' => $id, 'command' => $command, 'env' => $env, 'detach' => $detach];
            $tool = SandboxTools::PATH.'/hosting';

            return match (true) {
                // Every tool current, on the current base.
                isset($env['ONEDROP_TOOL_PATHS']) => new ExecResult(0, collect(app(SandboxTools::class)->expected())->map(fn ($hash, $path) => "{$hash}  {$path}")->implode("\n")),
                $command === [$tool, 'inspect'] => new ExecResult(0, json_encode($this->manifest)),
                str_contains($command[2] ?? '', 'rev-parse --verify') => new ExecResult(0, $this->head."\n"),
                isset($env['ONEDROP_FROM']) => new ExecResult(0, $this->changes),
                ($command[0] ?? null) === 'sh' && str_contains($command[2] ?? '', 'errors.log') => new ExecResult(0, "200\n{$this->previewErrors}"),
                ($command[0] ?? null) === $tool && $command[1] === 'pack' => $this->packError
                    ? new ExecResult(1, '', $this->packError)
                    : new ExecResult(0, in_array('seed', $command, true) ? $this->packed : collect(explode("\n", $this->packed))->reject(fn ($line) => str_starts_with($line, 'seed') || str_starts_with($line, 'postgres'))->implode("\n")),
                default => new ExecResult(0, ''),
            };
        }

        /**
         * The commands run with the hosting tool, without its path.
         *
         * @return list<string>
         */
        public function hostingCommands(): array
        {
            return collect($this->executed)
                ->filter(fn ($run) => ($run['command'][0] ?? null) === SandboxTools::PATH.'/hosting')
                ->map(fn ($run) => implode(' ', array_slice($run['command'], 1, 1)).(in_array('seed', $run['command'], true) ? ' seed' : ''))
                ->values()->all();
        }
    };

    app()->instance(SandboxProvider::class, $provider);

    return $provider;
}

/**
 * Fly.io, Neon, Upstash and Cloudflare as a deploy sees them, recording what was asked.
 *
 * @param  array{builder_exit?: int}  $options
 */
function fakeHostingProviders(array $options = []): void
{
    Http::fake([
        'api.machines.dev/v1/apps' => Http::response(['id' => 'app1'], 201),
        'api.machines.dev/v1/apps/*/ip_assignments' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['ips' => []])
            : Http::response(['ip' => '1.2.3.4', 'shared' => true]),
        'api.machines.dev/v1/apps/*/volumes' => Http::response(['id' => 'vol_123']),
        'api.machines.dev/v1/apps/*/machines' => fn (Request $request) => Http::response([
            'id' => str_contains(json_encode($request['config']['metadata'] ?? []), 'builder') ? 'builder_1' : 'machine_app',
        ]),
        'api.machines.dev/v1/apps/*/machines/builder_1' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['state' => 'stopped', 'config' => [], 'events' => [['request' => ['exit_event' => ['exit_code' => $options['builder_exit'] ?? 0]]]]])
            : Http::response([]),
        // $GLOBALS['hostedMachineState'] changes the app machine's state (e.g. suspended).
        'api.machines.dev/v1/apps/*/machines/machine_app*' => fn () => Http::response(['state' => $GLOBALS['hostedMachineState'] ?? 'started', 'config' => ['image' => 'x']]),
        'api.machines.dev/v1/apps/*' => Http::response([], 202),
        'console.neon.tech/api/v2/projects' => Http::response(['project' => ['id' => 'neon_1'], 'connection_uris' => [['connection_uri' => 'postgresql://u:p@ep-1.neon.tech/neondb']]], 201),
        'console.neon.tech/api/v2/projects/*' => Http::response(['project' => ['id' => 'neon_1']]),
        'api.upstash.com/v2/redis/database' => Http::response(['database_id' => 'up_1', 'endpoint' => 'calm-fox-123', 'port' => 6379, 'password' => 'secret']),
        'api.upstash.com/v2/redis/database/*' => Http::response('"OK"'),
        'api.cloudflare.com/client/v4/accounts/*/workers/scripts/*/assets-upload-session' => Http::response(['success' => true, 'result' => ['jwt' => 'done-jwt', 'buckets' => []]]),
        'api.cloudflare.com/client/v4/accounts/*/workers/scripts/*/subdomain' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/accounts/*/workers/subdomain' => Http::response(['success' => true, 'result' => ['subdomain' => 'acme']]),
        'api.cloudflare.com/client/v4/accounts/*/workers/scripts/*' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/accounts/*/r2/buckets' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/accounts/*/tokens' => Http::response(['success' => true, 'result' => ['id' => 'tok_1', 'value' => 'token-value']]),
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['status' => 'COMPLETED']]),
        // The app's own answer: $GLOBALS['hostedAppStatus'] (and hostedAppBody, hostedAppHeaders) change it between deploys.
        '*.fly.dev' => fn () => Http::response($GLOBALS['hostedAppBody'] ?? 'ok', $GLOBALS['hostedAppStatus'] ?? 200, $GLOBALS['hostedAppHeaders'] ?? []),
    ]);
}

/**
 * Answer a hosted app's database command (WorkspaceDatabase on a Fly machine, HOST-007) by running the real
 * db.php against a local workspace, with the environment the command sets (`env KEY=VALUE… sh -c …`).
 *
 * @param  list<string>  $command
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runHostedDatabaseCommand(string $workspace, array $command): array
{
    $start = array_search('env', $command, true) + 1;
    $pairs = array_slice($command, $start, array_search('sh', array_slice($command, $start), true));
    $env = collect($pairs)->mapWithKeys(fn (string $pair) => [strstr($pair, '=', true) => substr(strstr($pair, '='), 1)])->all();
    $result = Process::env(['APP_WORKSPACE' => $workspace, ...$env])->run([PHP_BINARY, base_path('docker/sandbox/db.php')]);

    return ['exit_code' => $result->exitCode(), 'stdout' => $result->output(), 'stderr' => $result->errorOutput()];
}

/**
 * The request sent to start the app's own machine (not the builder).
 *
 * @return array<string, mixed>|null
 */
function appMachineRequest(): ?array
{
    $sent = Http::recorded(fn (Request $request) => $request->method() === 'POST'
        && preg_match('#/apps/[^/]+/machines(/machine_app)?$#', $request->url())
        && ($request['config']['metadata']['onedrop'] ?? null) === 'app');

    return $sent->last()[0] ?? null ? $sent->last()[0]->data() : null;
}
