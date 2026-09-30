<?php

use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\WorkspaceAuth;
use App\Sandbox\WorkspaceDatabase;
use App\Sandbox\WorkspaceSecrets;
use App\Sandbox\WorkspaceStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
function runDatabaseTool(string $workspace, array $request): array
{
    return json_decode(runDatabaseScript($workspace, json_encode($request)), true);
}

function runDatabaseScript(string $workspace, string $request): string
{
    return Process::env(['APP_WORKSPACE' => $workspace, 'APP_DB_REQUEST' => $request])
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
    return Process::env(['APP_WORKSPACE' => $workspace, 'APP_AUTH_REQUEST' => $request])
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
