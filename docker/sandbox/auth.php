<?php

/**
 * Users & Auth tool behind the workspace's Tools → Users & Auth panel.
 *
 * Reads the app's sign-in setup from /workspace/.zap/auth.json (written by the agent, see
 * guides/auth.md), lists the app's users from its own database, and saves sign-in provider
 * keys into the app's .env. Secret values never leave the sandbox: the platform only learns
 * whether each one is set.
 *
 * Reads one JSON request from $APP_AUTH_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * Usage: APP_AUTH_REQUEST='{"op":"status"}' php /opt/zap/auth.php
 */

declare(strict_types=1);

define('APP_DB_LIBRARY', true);

require __DIR__.'/db.php';

const MANIFEST = '.zap/auth.json';
const METHODS = ['password', 'google', 'github', 'microsoft', 'onedrop'];
const PROVIDERS = ['google', 'github', 'microsoft'];
const USER_COLUMNS = ['id', 'name', 'email', 'role', 'created_at', 'last_login_at', 'disabled_at', 'password_change_required'];
const DEFAULT_ROLES = ['admin', 'member'];
/** Helper operations every helper has had; newer ones must be listed in auth.json's "helper". */
const BASE_HELPER_OPS = ['create', 'set-password', 'sign-out'];
/** OneDrop sign-in: the app builder is the OAuth provider and writes these itself. */
const ONEDROP_VARS = ['client_id' => 'ONEDROP_CLIENT_ID', 'client_secret' => 'ONEDROP_CLIENT_SECRET', 'authorize_url' => 'ONEDROP_AUTHORIZE_URL', 'token_url' => 'ONEDROP_TOKEN_URL', 'userinfo_url' => 'ONEDROP_USERINFO_URL'];
const EXPORT_MAX = 50_000;
const USERS_PAGE_MAX = 100;
/** The app's own helper for what needs its auth library (hashing passwords), see guides/auth.md. */
const USERS_COMMAND = '.zap/users';
const USERS_COMMAND_SECONDS = 60;

/**
 * The app's sign-in setup, with defaults filled in, or null when the agent hasn't set it up.
 *
 * @return array{library: ?string, login_path: string, callback_path: string, methods: list<string>, env_file: string, users: array<string, string>}|null
 */
function manifest(): ?array
{
    $path = workspace().'/'.MANIFEST;

    if (! is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);

    if (! is_array($data)) {
        throw new ToolError(MANIFEST.' is not valid JSON. Ask the agent to fix it.');
    }

    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    $columns = [];

    foreach (USER_COLUMNS as $column) {
        $columns[$column] = is_string($users[$column] ?? null) && $users[$column] !== '' ? $users[$column] : $column;
    }

    $envFile = is_string($data['env_file'] ?? null) ? $data['env_file'] : '.env';

    // Keys are written to this file, so it must stay inside the workspace.
    if ($envFile === '' || str_starts_with($envFile, '/') || in_array('..', explode('/', $envFile), true)) {
        throw new ToolError('env_file in '.MANIFEST.' must be a path inside the project.');
    }

    $roles = array_values(array_unique(array_filter(is_array($data['roles'] ?? null) ? $data['roles'] : [], fn ($role) => is_string($role) && preg_match('/^[\w .-]{1,40}$/u', $role))));
    $helperOps = array_values(array_filter(is_array($data['helper'] ?? null) ? $data['helper'] : [], 'is_string'));

    return [
        'library' => is_string($data['library'] ?? null) ? $data['library'] : null,
        'roles' => $roles ?: DEFAULT_ROLES,
        'helper_ops' => array_values(array_unique([...BASE_HELPER_OPS, ...$helperOps])),
        'login_path' => safeAppPath($data['login_path'] ?? null, '/login'),
        'callback_path' => safeAppPath($data['callback_path'] ?? null, '/auth/{provider}/callback'),
        'methods' => array_values(array_intersect(METHODS, is_array($data['methods'] ?? null) ? $data['methods'] : [])),
        'env_file' => $envFile,
        'users' => ['table' => is_string($users['table'] ?? null) && $users['table'] !== '' ? $users['table'] : 'users'] + $columns,
    ];
}

function safeAppPath(mixed $path, string $default): string
{
    return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : $default;
}

function envVar(string $provider, string $key): string
{
    return strtoupper($provider).'_'.strtoupper($key);
}

/**
 * Whether each provider's keys are in the app's env file (never the values).
 *
 * @param  array{env_file: string}  $manifest
 * @return array<string, array{client_id: bool, client_secret: bool}>
 */
function keyStatus(array $manifest): array
{
    $env = readDotenv(workspace().'/'.$manifest['env_file']);
    $status = [];

    foreach (PROVIDERS as $provider) {
        foreach (['client_id', 'client_secret'] as $key) {
            $status[$provider][$key] = ($env[envVar($provider, $key)] ?? '') !== '';
        }
    }

    $status['onedrop'] = ['client_id' => ($env[ONEDROP_VARS['client_id']] ?? '') !== '', 'client_secret' => ($env[ONEDROP_VARS['client_secret']] ?? '') !== ''];

    return $status;
}

/**
 * @return array<string, mixed>
 */
function status(): array
{
    $manifest = manifest();

    if ($manifest === null) {
        return ['configured' => false];
    }

    return [
        'configured' => true,
        'library' => $manifest['library'],
        'login_path' => $manifest['login_path'],
        'callback_path' => $manifest['callback_path'],
        'methods' => $manifest['methods'],
        'env_file' => $manifest['env_file'],
        'users_table' => $manifest['users']['table'],
        'keys' => keyStatus($manifest),
        'roles' => $manifest['roles'],
        'can_create' => is_file(workspace().'/'.USERS_COMMAND) && is_executable(workspace().'/'.USERS_COMMAND),
    ];
}

/**
 * The first of the app's databases that has the users table.
 *
 * @return array{0: PDO, 1: string, 2: array{name: string, schema: ?string, table: string, type: string}}
 */
function usersTable(string $name): array
{
    $problem = null;
    $connections = connections();

    if ($connections === []) {
        throw new ToolError("Couldn't find your app's database. Ask the agent to put its connection in .env as DATABASE_URL (or DB_CONNECTION for Laravel).");
    }

    foreach ($connections as $connection) {
        try {
            [$pdo, $driver] = open($connection['id']);

            foreach (tables($pdo, $driver) as $table) {
                if ($table['name'] === $name) {
                    return [$pdo, $driver, $table];
                }
            }
        } catch (ToolError|PDOException $e) {
            $problem ??= $e->getMessage();
        }
    }

    throw new ToolError($problem ?? "Couldn't find the {$name} table in your app's database yet. It appears once the app has run its migrations.");
}

/**
 * One page of the app's users, newest first, optionally searched by name or email.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function users(array $request, int $max = USERS_PAGE_MAX): array
{
    $manifest = manifest() ?? throw new ToolError("Sign-in isn't set up in this app yet.");
    $mapping = $manifest['users'];
    [$pdo, $driver, $table] = usersTable($mapping['table']);

    $existing = array_column(columns($pdo, $driver, $table), 'name');
    $select = [];

    foreach (USER_COLUMNS as $field) {
        $select[] = (in_array($mapping[$field], $existing, true) ? quote($mapping[$field], $driver) : 'NULL').' AS '.quote($field, $driver);
    }

    $where = '';
    $bindings = [];
    $search = trim((string) ($request['search'] ?? ''));
    $searchable = array_values(array_filter([$mapping['name'], $mapping['email']], fn ($column) => in_array($column, $existing, true)));

    if ($search !== '' && $searchable !== []) {
        // '!' is the escape character: portable across SQLite, Postgres and MySQL.
        $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $cast = $driver === 'mysql' ? 'CHAR' : 'TEXT';
        $where = ' WHERE '.implode(' OR ', array_map(fn ($column) => 'CAST('.quote($column, $driver)." AS {$cast}) {$like} ? ESCAPE '!'", $searchable));
        $bindings = array_fill(0, count($searchable), '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
    }

    $orderColumn = in_array($mapping['created_at'], $existing, true) ? $mapping['created_at'] : (in_array($mapping['id'], $existing, true) ? $mapping['id'] : null);
    $order = $orderColumn === null ? '' : ' ORDER BY '.quote($orderColumn, $driver).' DESC';
    $perPage = max(1, min($max, (int) ($request['per_page'] ?? 25)));
    $page = max(1, (int) ($request['page'] ?? 1));
    $from = quoteTable($table, $driver);

    $count = $pdo->prepare("SELECT COUNT(*) FROM {$from}{$where}");
    $count->execute($bindings);

    $rows = $pdo->prepare('SELECT '.implode(', ', $select)." FROM {$from}{$where}{$order} LIMIT {$perPage} OFFSET ".(($page - 1) * $perPage));
    $rows->execute($bindings);

    return [
        'users' => array_map(fn (array $user) => [
            ...$user,
            'password_change_required' => $user['password_change_required'] === null ? null : filter_var($user['password_change_required'], FILTER_VALIDATE_BOOLEAN),
        ], cells($rows->fetchAll())),
        'total' => (int) $count->fetchColumn(),
        'page' => $page,
        'per_page' => $perPage,
        'capabilities' => capabilities($manifest, $existing),
        'roles' => $manifest['roles'],
    ];
}

/**
 * Which account controls the app supports: the .zap/users helper (add users, set passwords,
 * sign out everywhere, sign in as someone) and the columns the app checks (turned-off accounts,
 * forced password change, roles).
 *
 * @param  array{users: array<string, string>, helper_ops: list<string>}  $manifest
 * @param  list<string>  $existing  the users table's columns
 * @return array{helper: bool, disable: bool, require_password_change: bool, roles: bool, sign_in_as: bool}
 */
function capabilities(array $manifest, array $existing): array
{
    $mapping = $manifest['users'];
    $helper = is_file(workspace().'/'.USERS_COMMAND) && is_executable(workspace().'/'.USERS_COMMAND);

    return [
        'helper' => $helper,
        'disable' => in_array($mapping['disabled_at'], $existing, true),
        'require_password_change' => in_array($mapping['password_change_required'], $existing, true),
        'roles' => in_array($mapping['role'], $existing, true),
        'sign_in_as' => $helper && in_array('sign-in-link', $manifest['helper_ops'], true),
    ];
}

/**
 * How many users signed in at or after $request['since'] ('Y-m-d H:i:s', UTC); null when there's no last-login column.
 *
 * @param  array<string, mixed>  $request
 * @return array{count: int|null}
 */
function active(array $request): array
{
    $manifest = manifest() ?? throw new ToolError("Sign-in isn't set up in this app yet.");
    $column = $manifest['users']['last_login_at'];
    [$pdo, $driver, $table] = usersTable($manifest['users']['table']);

    if (! in_array($column, array_column(columns($pdo, $driver, $table), 'name'), true)) {
        return ['count' => null];
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM '.quoteTable($table, $driver).' WHERE '.quote($column, $driver).' >= ?');
    $count->execute([(string) ($request['since'] ?? '')]);

    return ['count' => (int) $count->fetchColumn()];
}

/**
 * Save a provider's client ID and/or secret into the app's env file.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function saveKeys(array $request): array
{
    $manifest = manifest() ?? throw new ToolError("Sign-in isn't set up in this app yet.");
    $provider = $request['provider'] ?? null;

    if (! in_array($provider, PROVIDERS, true)) {
        throw new ToolError('Unknown sign-in provider.');
    }

    $values = [];

    foreach (['client_id', 'client_secret'] as $key) {
        $value = $request[$key] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9._~+\/=:@-]{1,500}$/', $value)) {
            throw new ToolError('That key has characters a client ID or secret never has. Paste it again.');
        }

        $values[envVar($provider, $key)] = $value;
    }

    if ($values === []) {
        throw new ToolError('Enter a client ID or secret to save.');
    }

    writeEnv($manifest, $values);

    return status();
}

/**
 * Set KEY=value lines in the app's env file, replacing existing ones and keeping everything else.
 * Values are checked by the callers to be single-line and unquoted-safe.
 *
 * @param  array{env_file: string}  $manifest
 * @param  array<string, string>  $values
 */
function writeEnv(array $manifest, array $values): void
{
    $path = workspace().'/'.$manifest['env_file'];
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];

    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) && isset($values[$m[1]])) {
            $lines[$i] = "{$m[1]}={$values[$m[1]]}";
            unset($values[$m[1]]);
        }
    }

    foreach ($values as $name => $value) {
        $lines[] = "{$name}={$value}";
    }

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, implode("\n", $lines)."\n");
    chmod($path, 0600);
}

/**
 * Save the app's OneDrop sign-in settings (written by the app builder, which is the provider).
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function saveOneDrop(array $request): array
{
    // Also used before the agent has set up sign-in (OneDrop chosen at setup): the app will read .env.
    $manifest = manifest() ?? ['env_file' => '.env'];
    $values = [];

    foreach (ONEDROP_VARS as $key => $name) {
        $value = $request[$key] ?? null;
        $valid = str_ends_with($key, '_url')
            ? is_string($value) && preg_match('#^https?://[^\s"\'\\\\]{1,500}$#', $value)
            : is_string($value) && preg_match('/^[A-Za-z0-9._~-]{1,200}$/', $value);

        $valid || throw new ToolError("The OneDrop {$key} is not valid.");
        $values[$name] = $value;
    }

    writeEnv($manifest, $values);

    return status();
}

/**
 * Every user, for a CSV download.
 *
 * @return array<string, mixed>
 */
function export(): array
{
    $page = users(['per_page' => EXPORT_MAX], EXPORT_MAX);

    return ['users' => $page['users'], 'truncated' => $page['total'] > EXPORT_MAX];
}

/**
 * A one-time path in the app that signs the browser in as this user, from the app's helper.
 *
 * @param  array<string, mixed>  $request
 * @return array{path: string}
 */
function signInLink(array $request): array
{
    findUser($request['id'] ?? null);

    in_array('sign-in-link', manifest()['helper_ops'], true)
        || throw new ToolError('Signing in as a user needs an update to your app. Ask the agent to add it.');

    $path = usersCommand(['op' => 'sign-in-link', 'id' => (string) $request['id']])['path'] ?? null;

    if (! is_string($path) || safeAppPath($path, '') === '') {
        throw new ToolError('Your app gave back a sign-in link that isn\'t a path in the app.');
    }

    return ['path' => $path];
}

/**
 * The users table and the user with this id in it, checked to exist.
 *
 * @return array{0: PDO, 1: string, 2: string, 3: array<string, string>, 4: list<string>} pdo, driver, quoted table, column mapping, existing columns
 */
function findUser(mixed $id): array
{
    $manifest = manifest() ?? throw new ToolError("Sign-in isn't set up in this app yet.");
    $mapping = $manifest['users'];
    [$pdo, $driver, $table] = usersTable($mapping['table']);
    $existing = array_column(columns($pdo, $driver, $table), 'name');
    $from = quoteTable($table, $driver);

    if (! is_scalar($id) || (string) $id === '') {
        throw new ToolError('Choose a user.');
    }

    $found = $pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE ".quote($mapping['id'], $driver).' = ?');
    $found->execute([(string) $id]);

    if ((int) $found->fetchColumn() === 0) {
        throw new ToolError("That user doesn't exist any more. Refresh the list.");
    }

    return [$pdo, $driver, $from, $mapping, $existing];
}

function validEmail(mixed $email): string
{
    $email = trim((string) $email);

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new ToolError('Enter a valid email address.');
    }

    return $email;
}

/**
 * Change a user's name and/or email.
 *
 * @param  array<string, mixed>  $request
 * @return array{updated: bool}
 */
function updateUser(array $request): array
{
    [$pdo, $driver, $from, $mapping, $existing] = findUser($request['id'] ?? null);
    $values = [];

    if (array_key_exists('name', $request) && in_array($mapping['name'], $existing, true)) {
        $values[$mapping['name']] = trim((string) $request['name']);
    }

    if (array_key_exists('email', $request) && in_array($mapping['email'], $existing, true)) {
        $values[$mapping['email']] = validEmail($request['email']);
    }

    // The app checks these on every request (guides/auth.md); apps set up before them need the agent first.
    if (array_key_exists('disabled', $request)) {
        in_array($mapping['disabled_at'], $existing, true) || throw new ToolError('Turning accounts off needs an update to your app. Ask the agent to add it.');
        $values[$mapping['disabled_at']] = $request['disabled'] ? gmdate('Y-m-d H:i:s') : null;
    }

    if (array_key_exists('role', $request)) {
        in_array($mapping['role'], $existing, true) || throw new ToolError('Roles need an update to your app. Ask the agent to add them.');
        in_array($request['role'], manifest()['roles'], true) || throw new ToolError('Your app has no role called that.');
        $values[$mapping['role']] = $request['role'];
    }

    if (array_key_exists('password_change_required', $request)) {
        in_array($mapping['password_change_required'], $existing, true) || throw new ToolError('Requiring a new password needs an update to your app. Ask the agent to add it.');
        // '1'/'0' are valid booleans in SQLite, Postgres and MySQL alike.
        $values[$mapping['password_change_required']] = $request['password_change_required'] ? '1' : '0';
    }

    if ($values === []) {
        throw new ToolError('Nothing to change.');
    }

    $set = implode(', ', array_map(fn ($column) => quote($column, $driver).' = ?', array_keys($values)));
    $pdo->prepare("UPDATE {$from} SET {$set} WHERE ".quote($mapping['id'], $driver).' = ?')
        ->execute([...array_values($values), (string) $request['id']]);

    return ['updated' => true];
}

/**
 * Delete a user. Their sessions and linked accounts go too when the app's tables cascade;
 * a foreign key that doesn't is reported and nothing is deleted.
 *
 * @param  array<string, mixed>  $request
 * @return array{deleted: bool}
 */
function deleteUser(array $request): array
{
    [$pdo, $driver, $from, $mapping] = findUser($request['id'] ?? null);

    $pdo->beginTransaction();

    try {
        $pdo->prepare("DELETE FROM {$from} WHERE ".quote($mapping['id'], $driver).' = ?')->execute([(string) $request['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();

        throw new ToolError("Couldn't delete this user, probably because other data in your app belongs to them: ".$e->getMessage());
    }

    return ['deleted' => true];
}

/**
 * Run the app's users command with one JSON request on stdin, returning its JSON reply.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function usersCommand(array $request): array
{
    $command = workspace().'/'.USERS_COMMAND;

    if (! is_file($command) || ! is_executable($command)) {
        throw new ToolError('Adding users and setting passwords needs a helper in your app. Ask the agent to add it.');
    }

    // The request goes on stdin so the password never appears in a command line.
    $process = proc_open([$command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, workspace());

    if (! is_resource($process)) {
        throw new ToolError("Couldn't run ".USERS_COMMAND.'.');
    }

    fwrite($pipes[0], json_encode($request, JSON_UNESCAPED_UNICODE)."\n");
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $errors = '';
    $deadline = microtime(true) + USERS_COMMAND_SECONDS;

    while (($status = proc_get_status($process))['running']) {
        if (microtime(true) > $deadline) {
            proc_terminate($process);

            throw new ToolError(USERS_COMMAND.' took too long to answer.');
        }

        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);
        usleep(20_000);
    }

    $output .= stream_get_contents($pipes[1]);
    $errors .= stream_get_contents($pipes[2]);
    proc_close($process);

    // Frameworks print banners and warnings; the reply is the last JSON line.
    foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
        $reply = json_decode($line, true);

        if (is_array($reply) && isset($reply['ok'])) {
            if ($reply['ok'] !== true) {
                throw new ToolError((string) ($reply['error'] ?? 'Your app refused the request.'));
            }

            return $reply;
        }
    }

    $detail = trim(substr(trim($errors ?: $output), -300));

    throw new ToolError(USERS_COMMAND." didn't answer as expected (exit code {$status['exitcode']})".($detail !== '' ? ": {$detail}" : '.'));
}

function validPassword(mixed $password): string
{
    if (! is_string($password) || strlen($password) < 8) {
        throw new ToolError('Passwords need at least 8 characters.');
    }

    return $password;
}

/**
 * Create a user with a password, through the app's own auth library.
 *
 * @param  array<string, mixed>  $request
 * @return array{created: bool, id: mixed}
 */
function createUser(array $request): array
{
    $reply = usersCommand([
        'op' => 'create',
        'name' => trim((string) ($request['name'] ?? '')),
        'email' => validEmail($request['email'] ?? ''),
        'password' => validPassword($request['password'] ?? null),
    ]);

    return ['created' => true, 'id' => $reply['id'] ?? null];
}

/**
 * Set a user's password, through the app's own auth library.
 *
 * @param  array<string, mixed>  $request
 * @return array{updated: bool}
 */
function setPassword(array $request): array
{
    findUser($request['id'] ?? null);
    usersCommand(['op' => 'set-password', 'id' => (string) $request['id'], 'password' => validPassword($request['password'] ?? null)]);

    if (! empty($request['sign_out'])) {
        signOut($request);
    }

    return ['updated' => true];
}

/**
 * End all of a user's sessions, through the app's own helper.
 *
 * @param  array<string, mixed>  $request
 * @return array{signed_out: bool}
 */
function signOut(array $request): array
{
    findUser($request['id'] ?? null);
    usersCommand(['op' => 'sign-out', 'id' => (string) $request['id']]);

    return ['signed_out' => true];
}

/**
 * @return array{helper: bool, disable: bool, require_password_change: bool, roles: bool, sign_in_as: bool}
 */
function appCapabilities(): array
{
    $manifest = manifest() ?? throw new ToolError("Sign-in isn't set up in this app yet.");
    [$pdo, $driver, $table] = usersTable($manifest['users']['table']);

    return capabilities($manifest, array_column(columns($pdo, $driver, $table), 'name'));
}

try {
    $request = json_decode((string) getenv('APP_AUTH_REQUEST'), true);

    if (! is_array($request)) {
        throw new ToolError('The request was not valid JSON.');
    }

    respond(['ok' => true, 'data' => match ($request['op'] ?? null) {
        'status' => status(),
        'users' => users($request),
        'active' => active($request),
        'keys' => saveKeys($request),
        'update-user' => updateUser($request),
        'delete-user' => deleteUser($request),
        'create-user' => createUser($request),
        'set-password' => setPassword($request),
        'sign-out' => signOut($request),
        'capabilities' => appCapabilities(),
        'export' => export(),
        'sign-in-link' => signInLink($request),
        'onedrop' => saveOneDrop($request),
        default => throw new ToolError('Unknown operation.'),
    }]);
} catch (ToolError|PDOException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()]);
}
