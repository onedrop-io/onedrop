<?php

/**
 * Database tool behind the workspace's Tools → Database panel.
 *
 * Finds the app's databases (from .env files, SQLite files in the workspace and database containers in the
 * sandbox's own Docker), then browses, edits or queries one of them. Connection details and passwords
 * never leave the sandbox: the platform only ever sees connection ids.
 *
 * Reads one JSON request from $APP_DB_REQUEST (or stdin, when it's unset) and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * On a hosted app (ONEDROP_HOSTED=1, HOST-007) the same tool runs on its machine: the database it was given
 * (DATABASE_URL) comes first, and servers named in .env files, which only existed in the sandbox, are left out.
 *
 * Usage: APP_DB_REQUEST='{"op":"connections"}' php /opt/onedrop/db.php
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const PAGE_SIZE_MAX = 100;
const QUERY_ROWS_MAX = 500;
const CELL_BYTES_MAX = 10_000;
const SQLITE_SCAN_DEPTH = 4;
const SKIPPED_DIRS = ['node_modules', 'vendor', '.git', '.cache', '.onedrop', 'storage/framework'];
const FILTER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'null', 'notnull'];

final class ToolError extends RuntimeException {}

function workspace(): string
{
    return rtrim(getenv('APP_WORKSPACE') ?: '/workspace', '/');
}

function respond(array $response): never
{
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION), "\n";
    exit(0);
}

/**
 * KEY=value pairs from a dotenv file (comments, "export" and quotes handled; no interpolation).
 *
 * @return array<string, string>
 */
function readDotenv(string $path): array
{
    $values = [];

    foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }

        $value = trim($m[2]);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $end = strpos($value, $quote, 1);
            $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        $values[$m[1]] = $value;
    }

    return $values;
}

/**
 * Workspace-relative path, or the absolute path when it's outside the workspace.
 */
function relative(string $path): string
{
    $root = workspace().'/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

function resolvePath(string $path, string $base): string
{
    return str_starts_with($path, '/') ? $path : rtrim($base, '/').'/'.preg_replace('#^\./#', '', $path);
}

function isSqliteFile(string $path): bool
{
    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return false;
    }

    $header = fread($handle, 16);
    fclose($handle);

    return $header === "SQLite format 3\0";
}

/**
 * A connection from a database URL (postgres://, mysql://, sqlite:, file:).
 *
 * @return array<string, mixed>|null
 */
function fromUrl(string $url, string $envDir): ?array
{
    if (preg_match('#^(sqlite|file):(//)?(.+)$#', $url, $m)) {
        $path = preg_replace('/\?.*$/', '', $m[3]);

        // Prisma resolves file: URLs next to its schema.
        foreach ([resolvePath($path, $envDir), resolvePath($path, $envDir.'/prisma')] as $candidate) {
            if (is_file($candidate)) {
                return sqliteConnection($candidate);
            }
        }

        return sqliteConnection(resolvePath($path, $envDir));
    }

    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $driver = match ($scheme) {
        'postgres', 'postgresql' => 'pgsql',
        'mysql', 'mariadb' => 'mysql',
        default => null,
    };

    if ($driver === null || empty($parts['host'])) {
        return null;
    }

    parse_str($parts['query'] ?? '', $query);

    return serverConnection(
        $driver,
        $parts['host'],
        isset($parts['port']) ? (int) $parts['port'] : null,
        ltrim(rawurldecode($parts['path'] ?? ''), '/'),
        isset($parts['user']) ? rawurldecode($parts['user']) : null,
        isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        is_string($query['sslmode'] ?? null) ? $query['sslmode'] : null,
    );
}

/**
 * @return array<string, mixed>
 */
function sqliteConnection(string $path): array
{
    return [
        'id' => 'sqlite:'.relative($path),
        'driver' => 'sqlite',
        'summary' => relative($path),
        'path' => $path,
    ];
}

/**
 * @return array<string, mixed>
 */
function serverConnection(string $driver, string $host, ?int $port, string $database, ?string $user, ?string $password, ?string $sslmode = null): array
{
    $port ??= $driver === 'pgsql' ? 5432 : 3306;
    $dsn = $driver === 'pgsql'
        ? "pgsql:host={$host};port={$port};dbname={$database}".($sslmode ? ";sslmode={$sslmode}" : '')
        : "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    return [
        'driver' => $driver,
        'summary' => ($user ? "{$user}@" : '')."{$host}:{$port}/{$database}",
        'dsn' => $dsn,
        'user' => $user,
        'password' => $password,
        'server' => compact('host', 'port', 'database', 'sslmode'),
    ];
}

/**
 * The docker command for the sandbox's own Docker (SBX-008), or null when it isn't running.
 */
function dockerBinary(): ?string
{
    return getenv('ONEDROP_DOCKER_BIN') ?: (file_exists('/var/run/docker.sock') ? 'docker' : null);
}

/**
 * Database servers running as containers in the sandbox's Docker, e.g. a compose stack's `db` service:
 * the image says which kind, the container's env holds its credentials, and it's reached on a port published
 * to the sandbox, else at the container's own address.
 *
 * @return list<array{connection: array<string, mixed>, names: list<string>, address: callable(int): array{0: string, 1: int}}>
 */
function databaseContainers(): array
{
    $docker = dockerBinary();

    if ($docker === null) {
        return [];
    }

    exec(escapeshellarg($docker).' ps --quiet --no-trunc 2>/dev/null', $ids, $code);
    $ids = array_filter(array_map('trim', $ids));

    if ($code !== 0 || $ids === []) {
        return [];
    }

    exec(escapeshellarg($docker).' inspect '.implode(' ', array_map('escapeshellarg', $ids)).' 2>/dev/null', $out, $code);
    $inspected = $code === 0 ? json_decode(implode("\n", $out), true) : null;
    $found = [];

    foreach (is_array($inspected) ? $inspected : [] as $container) {
        if (! is_array($container)) {
            continue;
        }

        $env = [];
        foreach ($container['Config']['Env'] ?? [] as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $env[$key] = $value;
        }

        if (! ($kind = databaseImage((string) ($container['Config']['Image'] ?? '')) ?? databaseImageEnv($env))) {
            continue;
        }

        // Credentials kept in Docker secrets (POSTGRES_PASSWORD_FILE=/run/secrets/db_password) are read from the container.
        foreach ($env as $key => $path) {
            $base = substr($key, 0, -5);
            if (str_ends_with($key, '_FILE') && preg_match('/^(POSTGRES|POSTGRESQL|MYSQL|MARIADB)_/', $base) && ! isset($env[$base])) {
                exec(escapeshellarg($docker).' exec '.escapeshellarg((string) $container['Id']).' cat '.escapeshellarg($path).' 2>/dev/null', $contents, $read);
                if ($read === 0) {
                    $env[$base] = rtrim(implode("\n", $contents), "\n");
                }
                $contents = [];
            }
        }

        $name = ltrim((string) ($container['Name'] ?? ''), '/');
        $labels = $container['Config']['Labels'] ?? [];
        $service = $labels['com.docker.compose.service'] ?? null;
        $names = array_filter([$name, $service, ...array_merge(...array_map(
            fn ($network) => [...($network['Aliases'] ?? []), ...($network['DNSNames'] ?? [])],
            array_values($container['NetworkSettings']['Networks'] ?? []),
        ))]);
        $ip = current(array_filter(array_column(array_values($container['NetworkSettings']['Networks'] ?? []), 'IPAddress'))) ?: null;
        $published = $container['NetworkSettings']['Ports'] ?? [];

        // A port inside the container, reached where it's published if it is; or a port it publishes to the sandbox.
        $address = function (int $port) use ($published, $ip): array {
            foreach ($published["{$port}/tcp"] ?? [] as $binding) {
                if (! empty($binding['HostPort'])) {
                    return ['127.0.0.1', (int) $binding['HostPort']];
                }
            }

            foreach ($published as $bindings) {
                if (in_array((string) $port, array_column($bindings ?? [], 'HostPort'), true)) {
                    return ['127.0.0.1', $port];
                }
            }

            return [$ip ?? '127.0.0.1', $port];
        };

        if ($kind === 'pgsql') {
            $user = $env['POSTGRES_USER'] ?? $env['POSTGRESQL_USERNAME'] ?? 'postgres';
            $password = $env['POSTGRES_PASSWORD'] ?? $env['POSTGRESQL_PASSWORD'] ?? $env['PGPASSWORD'] ?? null;
            $database = $env['POSTGRES_DB'] ?? $env['POSTGRESQL_DATABASE'] ?? $user;
            $port = (int) ($env['PGPORT'] ?? $env['POSTGRESQL_PORT_NUMBER'] ?? 5432);
        } else {
            $rootPassword = $env['MYSQL_ROOT_PASSWORD'] ?? $env['MARIADB_ROOT_PASSWORD'] ?? null;
            $emptyRoot = ($env['MYSQL_ALLOW_EMPTY_PASSWORD'] ?? $env['MARIADB_ALLOW_EMPTY_ROOT_PASSWORD'] ?? '') !== '';
            [$user, $password] = $rootPassword !== null || $emptyRoot || ! isset($env['MYSQL_USER'])
                ? ['root', $rootPassword ?? '']
                : [$env['MYSQL_USER'], $env['MYSQL_PASSWORD'] ?? ''];
            $database = $env['MYSQL_DATABASE'] ?? $env['MARIADB_DATABASE'] ?? '';
            $port = (int) ($env['MYSQL_TCP_PORT'] ?? 3306);
        }

        [$host, $hostPort] = $address($port);
        $label = $service ?? $name;

        $found[] = [
            'connection' => [
                ...serverConnection($kind, $host, $hostPort, $database, $user, $password),
                'id' => "docker:{$name}",
                'summary' => "{$user}@{$label}:{$port}/{$database}",
                'source' => "Docker container {$name}",
            ],
            'names' => array_values(array_unique($names)),
            'address' => $address,
        ];
    }

    return $found;
}

/**
 * 'pgsql' or 'mysql' for an image built on an official database image (FROM postgres:16 in the project's own
 * Dockerfile), which keeps the version variables the official image sets, else null.
 *
 * @param  array<string, string>  $env
 */
function databaseImageEnv(array $env): ?string
{
    return match (true) {
        isset($env['PG_MAJOR']) || isset($env['PG_VERSION']) => 'pgsql',
        isset($env['MARIADB_VERSION']) || isset($env['MYSQL_MAJOR']) || isset($env['MYSQL_VERSION']) => 'mysql',
        default => null,
    };
}

/**
 * 'pgsql' or 'mysql' for a database server's image (postgres:16, supabase/postgres:15.8, mariadb:11, …), else null.
 */
function databaseImage(string $image): ?string
{
    $repository = strtolower(basename((string) preg_replace('/(@.*|:[^\/]*)$/', '', $image)));

    return match (true) {
        in_array($repository, ['postgres', 'postgresql', 'postgis', 'pgvector', 'timescaledb', 'timescaledb-ha'], true) => 'pgsql',
        in_array($repository, ['mysql', 'mysql-server', 'mariadb', 'percona-server'], true) => 'mysql',
        default => null,
    };
}

/**
 * Connections declared by a dotenv file: a database URL in any variable (DATABASE_URL, SUPABASE_DB_URL, …), or
 * else separate settings: Laravel's DB_*, Postgres' PG*, POSTGRES_HOST/MYSQL_HOST and their siblings.
 *
 * @return list<array<string, mixed>>
 */
function envConnections(string $file): array
{
    $env = readDotenv($file);
    $dir = dirname($file);
    $source = relative($file);
    $found = [];
    $urls = [];

    // The usual names first, then any other variable that holds a server URL (sqlite:/file: only from the usual ones).
    $named = ['DATABASE_URL', 'DB_URL', 'POSTGRES_URL', 'MYSQL_URL'];
    $keys = [...array_intersect($named, array_keys($env)), ...array_filter(
        array_diff(array_keys($env), $named),
        fn (string $key) => preg_match('#^(postgres(ql)?|mysql|mariadb)://#i', $env[$key]),
    )];

    foreach ($keys as $key) {
        if ($env[$key] !== '' && ! isset($urls[$env[$key]]) && ($connection = fromUrl($env[$key], $dir))) {
            $urls[$env[$key]] = true;
            $found[] = $connection + ['id' => "env:{$source}:{$key}", 'source' => "{$source} ({$key})"];
        }
    }

    if ($found !== []) {
        return $found;
    }

    $driver = strtolower($env['DB_CONNECTION'] ?? $env['DB_DRIVER'] ?? $env['DB_TYPE'] ?? $env['DB_DIALECT'] ?? $env['DB_CLIENT'] ?? '');

    if ($driver === 'sqlite') {
        $database = $env['DB_DATABASE'] ?? '';
        $path = $database === '' || $database === ':memory:' ? 'database/database.sqlite' : $database;

        return [sqliteConnection(resolvePath($path, $dir)) + ['source' => "{$source} (DB_CONNECTION)"]];
    }

    $driver = match (true) {
        in_array($driver, ['pgsql', 'postgres', 'postgresql', 'pg'], true) => 'pgsql',
        in_array($driver, ['mysql', 'mysql2', 'mariadb'], true) => 'mysql',
        $driver === '' && isset($env['DB_HOST']) => ['5432' => 'pgsql', '3306' => 'mysql'][$env['DB_PORT'] ?? ''] ?? null,
        default => null,
    };

    // [driver, the variable that names the set, host, port, database, user, password]
    $sets = [
        [$driver, isset($env['DB_CONNECTION']) ? 'DB_CONNECTION' : 'DB_HOST', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? null,
            $env['DB_DATABASE'] ?? $env['DB_NAME'] ?? '', $env['DB_USERNAME'] ?? $env['DB_USER'] ?? null, $env['DB_PASSWORD'] ?? $env['DB_PASS'] ?? null],
        ['pgsql', 'PGHOST', $env['PGHOST'] ?? null, $env['PGPORT'] ?? null,
            $env['PGDATABASE'] ?? $env['PGUSER'] ?? 'postgres', $env['PGUSER'] ?? 'postgres', $env['PGPASSWORD'] ?? null],
        ['pgsql', 'POSTGRES_HOST', $env['POSTGRES_HOST'] ?? null, $env['POSTGRES_PORT'] ?? null,
            $env['POSTGRES_DB'] ?? $env['POSTGRES_DATABASE'] ?? $env['POSTGRES_USER'] ?? 'postgres', $env['POSTGRES_USER'] ?? 'postgres', $env['POSTGRES_PASSWORD'] ?? null],
        ['mysql', 'MYSQL_HOST', $env['MYSQL_HOST'] ?? null, $env['MYSQL_PORT'] ?? null,
            $env['MYSQL_DATABASE'] ?? '', $env['MYSQL_USER'] ?? 'root', isset($env['MYSQL_USER']) ? $env['MYSQL_PASSWORD'] ?? null : $env['MYSQL_ROOT_PASSWORD'] ?? $env['MYSQL_PASSWORD'] ?? null],
    ];

    foreach ($sets as [$kind, $key, $host, $port, $database, $user, $password]) {
        // A host that's a socket path (Supabase's POSTGRES_HOST=/var/run/postgresql) is only reachable inside its container.
        if ($kind !== null && $host !== null && $host !== '' && $host[0] !== '/') {
            $found[] = serverConnection($kind, $host, is_numeric($port) ? (int) $port : null, $database, $user, $password)
                + ['id' => "env:{$source}:{$key}", 'source' => "{$source} ({$key})"];
        }
    }

    return $found;
}

/**
 * SQLite files anywhere in the workspace (a few levels deep, skipping dependency folders).
 *
 * @return list<string>
 */
function sqliteFiles(string $dir, int $depth = 0): array
{
    $files = [];

    foreach (@scandir($dir) ?: [] as $name) {
        $path = "{$dir}/{$name}";

        // Symlinked files are followed (a hosted app's data files are links to its volume); symlinked folders aren't.
        if ($name === '.' || $name === '..' || (is_link($path) && is_dir($path)) || in_array(relative($path), SKIPPED_DIRS, true) || in_array($name, SKIPPED_DIRS, true)) {
            continue;
        }

        if (is_dir($path)) {
            if ($depth < SQLITE_SCAN_DEPTH) {
                array_push($files, ...sqliteFiles($path, $depth + 1));
            }
        } elseif (preg_match('/\.(sqlite3?|db3?)$/i', $name) && isSqliteFile($path)) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * Every database the app appears to use, declared ones first.
 *
 * @return list<array<string, mixed>>
 */
function connections(): array
{
    $root = workspace();
    $envFiles = [];

    foreach (array_merge([$root], glob($root.'/*', GLOB_ONLYDIR) ?: []) as $dir) {
        if (in_array(basename($dir), SKIPPED_DIRS, true)) {
            continue;
        }

        foreach (['.env', '.env.local'] as $name) {
            if (is_file("{$dir}/{$name}")) {
                $envFiles[] = "{$dir}/{$name}";
            }
        }
    }

    $byId = [];
    $containers = databaseContainers();
    $reached = [];
    $hosted = getenv('ONEDROP_HOSTED') === '1';

    // Hosted, the database it was given comes first.
    if ($hosted && ($url = getenv('DATABASE_URL')) && ($connection = fromUrl($url, $root))) {
        $byId['env:hosted:DATABASE_URL'] = $connection + ['id' => 'env:hosted:DATABASE_URL', 'source' => 'Hosting (DATABASE_URL)'];
    }

    foreach ($envFiles as $file) {
        foreach (envConnections($file) as $connection) {
            // A server in .env is the sandbox's own (127.0.0.1, a container): not there when hosted.
            if ($hosted && $connection['driver'] !== 'sqlite') {
                continue;
            }

            // A host like DB_HOST=db names a container, which only its compose network resolves: reach it directly.
            foreach (isset($connection['server']) ? $containers : [] as $index => $container) {
                if (in_array($connection['server']['host'], $container['names'], true)) {
                    $server = $connection['server'];
                    [$host, $port] = ($container['address'])($server['port']);
                    $connection = [...$connection, ...array_intersect_key(
                        serverConnection($connection['driver'], $host, $port, $server['database'], $connection['user'], $connection['password'], $server['sslmode']),
                        array_flip(['dsn', 'server']),
                    )];
                    $reached[$index] = true;
                    break;
                }
            }

            $byId[$connection['id']] ??= $connection;
        }
    }

    foreach ($containers as $index => $container) {
        if (! isset($reached[$index])) {
            $byId[$container['connection']['id']] ??= $container['connection'];
        }
    }

    foreach (sqliteFiles($root) as $path) {
        $connection = sqliteConnection($path) + ['source' => 'SQLite file'];
        $byId[$connection['id']] ??= $connection;
    }

    return array_values($byId);
}

/**
 * The public description of a connection (no DSN, user or password).
 *
 * @param  array<string, mixed>  $connection
 * @return array<string, mixed>
 */
function describe(array $connection): array
{
    $missing = $connection['driver'] === 'sqlite' && ! is_file($connection['path']);

    return [
        'id' => $connection['id'],
        'driver' => $connection['driver'],
        'label' => ['sqlite' => 'SQLite', 'pgsql' => 'PostgreSQL', 'mysql' => 'MySQL'][$connection['driver']],
        'summary' => $connection['summary'],
        'source' => $connection['source'] ?? null,
        'error' => $missing ? "The file {$connection['summary']} doesn't exist yet." : null,
    ];
}

/**
 * @return array{0: PDO, 1: string}
 */
function open(string $id): array
{
    foreach (connections() as $connection) {
        if ($connection['id'] !== $id) {
            continue;
        }

        $driver = $connection['driver'];

        if ($driver === 'sqlite' && ! is_file($connection['path'])) {
            throw new ToolError("The file {$connection['summary']} doesn't exist yet.");
        }

        try {
            $pdo = new PDO(
                $driver === 'sqlite' ? 'sqlite:'.$connection['path'] : $connection['dsn'],
                $connection['user'] ?? null,
                $connection['password'] ?? null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );
        } catch (PDOException $e) {
            throw new ToolError(str_contains($e->getMessage(), 'could not find driver')
                ? 'This sandbox has no driver for '.describe($connection)['label'].'. Rebuild the sandbox image and recreate the sandbox.'
                : "Couldn't connect to the database: ".$e->getMessage());
        }

        return [$pdo, $driver];
    }

    throw new ToolError("That database connection wasn't found. Refresh and pick another.");
}

function quote(string $identifier, string $driver): string
{
    return $driver === 'mysql'
        ? '`'.str_replace('`', '``', $identifier).'`'
        : '"'.str_replace('"', '""', $identifier).'"';
}

/**
 * @param  array{schema: ?string, table: string}  $table
 */
function quoteTable(array $table, string $driver): string
{
    return ($table['schema'] !== null ? quote($table['schema'], $driver).'.' : '').quote($table['table'], $driver);
}

/**
 * Tables and views, named "schema.table" outside Postgres's public schema.
 *
 * @return list<array{name: string, schema: ?string, table: string, type: string}>
 */
function tables(PDO $pdo, string $driver): array
{
    $rows = match ($driver) {
        'sqlite' => $pdo->query("SELECT NULL AS table_schema, name AS table_name, type AS table_type FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(),
        'pgsql' => $pdo->query("SELECT table_schema, table_name, table_type FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema') AND table_schema NOT LIKE 'pg_toast%' ORDER BY table_schema <> 'public', table_schema, table_name")->fetchAll(),
        'mysql' => $pdo->query('SELECT NULL AS table_schema, table_name AS table_name, table_type AS table_type FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll(),
    };

    return array_map(fn (array $row) => [
        'name' => $row['table_schema'] === null || $row['table_schema'] === 'public' ? $row['table_name'] : "{$row['table_schema']}.{$row['table_name']}",
        'schema' => $row['table_schema'],
        'table' => $row['table_name'],
        'type' => str_contains(strtolower((string) $row['table_type']), 'view') ? 'view' : 'table',
    ], $rows);
}

/**
 * @return array{name: string, schema: ?string, table: string, type: string}
 */
function findTable(PDO $pdo, string $driver, mixed $name): array
{
    foreach (tables($pdo, $driver) as $table) {
        if ($table['name'] === $name) {
            return $table;
        }
    }

    throw new ToolError("The table {$name} doesn't exist.");
}

/**
 * Column names of every table and view, keyed by table name, for the SQL runner's autocomplete.
 *
 * @return array<string, list<string>>
 */
function columnNames(PDO $pdo, string $driver): array
{
    $rows = match ($driver) {
        'sqlite' => $pdo->query("SELECT NULL AS table_schema, m.name AS table_name, p.name AS column_name FROM sqlite_master m, pragma_table_info(m.name) p WHERE m.type IN ('table', 'view') AND m.name NOT LIKE 'sqlite_%' ORDER BY m.name, p.cid")->fetchAll(),
        'pgsql' => $pdo->query("SELECT table_schema, table_name, column_name FROM information_schema.columns WHERE table_schema NOT IN ('pg_catalog', 'information_schema') AND table_schema NOT LIKE 'pg_toast%' ORDER BY table_schema, table_name, ordinal_position")->fetchAll(),
        'mysql' => $pdo->query('SELECT NULL AS table_schema, table_name AS table_name, column_name AS column_name FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position')->fetchAll(),
    };

    $names = [];

    foreach ($rows as $row) {
        $table = $row['table_schema'] === null || $row['table_schema'] === 'public' ? $row['table_name'] : "{$row['table_schema']}.{$row['table_name']}";
        $names[$table][] = $row['column_name'];
    }

    return $names;
}

/**
 * @return list<array{name: string, type: string, columns: list<string>}>
 */
function tablesWithColumns(PDO $pdo, string $driver): array
{
    $columns = columnNames($pdo, $driver);

    return array_map(fn (array $table) => [
        'name' => $table['name'],
        'type' => $table['type'],
        'columns' => $columns[$table['name']] ?? [],
    ], tables($pdo, $driver));
}

/**
 * @param  array{schema: ?string, table: string}  $table
 * @return list<array{name: string, type: string, nullable: bool, default: ?string, primary: bool, auto: bool}>
 */
function columns(PDO $pdo, string $driver, array $table): array
{
    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info('.quote($table['table'], $driver).')')->fetchAll();
        $primary = array_filter($rows, fn ($row) => (int) $row['pk'] > 0);

        return array_map(fn (array $row) => [
            'name' => $row['name'],
            'type' => $row['type'] !== '' ? strtolower($row['type']) : 'any',
            'nullable' => ! $row['notnull'] && ! $row['pk'],
            'default' => $row['dflt_value'],
            'primary' => (int) $row['pk'] > 0,
            // A lone INTEGER PRIMARY KEY is SQLite's rowid: filled in automatically.
            'auto' => (int) $row['pk'] > 0 && count($primary) === 1 && strtoupper($row['type']) === 'INTEGER',
        ], $rows);
    }

    if ($driver === 'pgsql') {
        $statement = $pdo->prepare("SELECT c.column_name, CASE c.data_type WHEN 'ARRAY' THEN substr(c.udt_name, 2) || '[]' WHEN 'USER-DEFINED' THEN c.udt_name ELSE c.data_type END AS data_type, c.is_nullable, c.column_default, c.is_identity,
            EXISTS (SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage k ON k.constraint_name = tc.constraint_name AND k.table_schema = tc.table_schema AND k.table_name = tc.table_name
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = c.table_schema AND tc.table_name = c.table_name AND k.column_name = c.column_name) AS is_primary
            FROM information_schema.columns c WHERE c.table_schema = ? AND c.table_name = ? ORDER BY c.ordinal_position");
        $statement->execute([$table['schema'], $table['table']]);

        return array_map(fn (array $row) => [
            'name' => $row['column_name'],
            'type' => $row['data_type'],
            'nullable' => $row['is_nullable'] === 'YES',
            'default' => $row['column_default'],
            'primary' => (bool) $row['is_primary'],
            'auto' => $row['is_identity'] === 'YES' || str_starts_with((string) $row['column_default'], 'nextval('),
        ], $statement->fetchAll());
    }

    $statement = $pdo->prepare('SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS dflt, column_key AS ckey, extra AS extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $statement->execute([$table['table']]);

    return array_map(fn (array $row) => [
        'name' => $row['name'],
        'type' => $row['type'],
        'nullable' => $row['nullable'] === 'YES',
        'default' => $row['dflt'],
        'primary' => $row['ckey'] === 'PRI',
        'auto' => str_contains((string) $row['extra'], 'auto_increment'),
    ], $statement->fetchAll());
}

/**
 * A value as JSON can carry it. Binary and very long values become a read-only preview.
 */
function cell(mixed $value): mixed
{
    if (is_resource($value)) {
        $value = stream_get_contents($value);
    }

    if (! is_string($value)) {
        return $value;
    }

    if (! mb_check_encoding($value, 'UTF-8')) {
        return ['preview' => null, 'bytes' => strlen($value)];
    }

    if (strlen($value) > CELL_BYTES_MAX) {
        return ['preview' => mb_strcut($value, 0, 500), 'bytes' => strlen($value)];
    }

    return $value;
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function cells(array $rows): array
{
    return array_map(fn (array $row) => array_map(cell(...), $row), $rows);
}

/**
 * Values ready to bind. Booleans become 1/0, which every driver accepts (PDO turns false into '').
 *
 * @param  array<array-key, mixed>  $values
 * @return list<mixed>
 */
function params(array $values): array
{
    return array_map(fn ($value) => is_bool($value) ? (int) $value : $value, array_values($values));
}

/**
 * Make sure every key is a real column; returns them in the given order.
 *
 * @param  array<string, mixed>  $values
 * @param  list<array{name: string}>  $columns
 * @return list<string>
 */
function knownColumns(array $values, array $columns): array
{
    $names = array_column($columns, 'name');

    foreach (array_keys($values) as $name) {
        if (! in_array($name, $names, true)) {
            throw new ToolError("The column {$name} doesn't exist.");
        }
    }

    return array_map('strval', array_keys($values));
}

/**
 * A WHERE clause from the grid's filters.
 *
 * @param  list<array{column?: mixed, operator?: mixed, value?: mixed}>  $filters
 * @param  list<array{name: string}>  $columns
 * @return array{0: string, 1: list<mixed>}
 */
function where(array $filters, array $columns, string $driver): array
{
    $clauses = [];
    $bindings = [];
    $names = array_column($columns, 'name');

    foreach ($filters as $filter) {
        $column = $filter['column'] ?? null;
        $operator = $filter['operator'] ?? 'eq';

        if (! in_array($column, $names, true) || ! in_array($operator, FILTER_OPERATORS, true)) {
            throw new ToolError('That filter is not valid.');
        }

        $quoted = quote($column, $driver);

        if ($operator === 'null' || $operator === 'notnull') {
            $clauses[] = $quoted.($operator === 'null' ? ' IS NULL' : ' IS NOT NULL');

            continue;
        }

        if ($operator === 'contains') {
            // '!' is the escape character: portable across SQLite, Postgres and MySQL.
            $text = $driver === 'mysql' ? "CAST({$quoted} AS CHAR)" : "CAST({$quoted} AS TEXT)";
            $clauses[] = $text.($driver === 'pgsql' ? ' ILIKE' : ' LIKE')." ? ESCAPE '!'";
            $bindings[] = '%'.strtr((string) ($filter['value'] ?? ''), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

            continue;
        }

        $clauses[] = $quoted.' '.['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operator].' ?';
        $bindings[] = $filter['value'] ?? null;
    }

    return [$clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses), $bindings];
}

/**
 * One page of a table's rows, with its columns and total row count.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function rows(PDO $pdo, string $driver, array $request): array
{
    $table = findTable($pdo, $driver, $request['table'] ?? null);
    $columns = columns($pdo, $driver, $table);
    $from = quoteTable($table, $driver);
    [$where, $bindings] = where(is_array($request['filters'] ?? null) ? $request['filters'] : [], $columns, $driver);

    $perPage = max(1, min(PAGE_SIZE_MAX, (int) ($request['per_page'] ?? 50)));
    $page = max(1, (int) ($request['page'] ?? 1));
    $primary = array_values(array_filter($columns, fn ($column) => $column['primary']));

    $sort = $request['sort'] ?? null;
    $direction = ($request['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    if ($sort !== null && ! in_array($sort, array_column($columns, 'name'), true)) {
        throw new ToolError("The column {$sort} doesn't exist.");
    }

    // Order by the primary key when nothing is chosen, so pages stay stable.
    $order = match (true) {
        $sort !== null => ' ORDER BY '.quote($sort, $driver).' '.$direction,
        $primary !== [] => ' ORDER BY '.implode(', ', array_map(fn ($column) => quote($column['name'], $driver), $primary)),
        default => '',
    };

    $count = $pdo->prepare("SELECT COUNT(*) FROM {$from}{$where}");
    $count->execute(params($bindings));

    $select = $pdo->prepare("SELECT * FROM {$from}{$where}{$order} LIMIT {$perPage} OFFSET ".(($page - 1) * $perPage));
    $select->execute(params($bindings));

    return [
        'table' => $table['name'],
        'type' => $table['type'],
        'columns' => $columns,
        'rows' => cells($select->fetchAll()),
        'total' => (int) $count->fetchColumn(),
        'page' => $page,
        'per_page' => $perPage,
    ];
}

/**
 * Apply inserts, updates and deletes to one table in a single transaction.
 * Updates and deletes find rows by their full primary key.
 *
 * @param  array<string, mixed>  $request
 * @return array{inserted: int, updated: int, deleted: int}
 */
function changes(PDO $pdo, string $driver, array $request): array
{
    $table = findTable($pdo, $driver, $request['table'] ?? null);

    if ($table['type'] === 'view') {
        throw new ToolError("{$table['name']} is a view, so it can't be edited.");
    }

    $columns = columns($pdo, $driver, $table);
    $from = quoteTable($table, $driver);
    $primary = array_column(array_filter($columns, fn ($column) => $column['primary']), 'name');
    $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

    $keyClause = function (mixed $key) use ($primary, $driver): array {
        if ($primary === []) {
            throw new ToolError('This table has no primary key, so rows can only be changed with SQL.');
        }

        if (! is_array($key) || array_diff($primary, array_keys($key)) !== [] || count($key) !== count($primary)) {
            throw new ToolError('Each changed row must be identified by its primary key.');
        }

        return [
            implode(' AND ', array_map(fn ($name) => quote($name, $driver).' = ?', $primary)),
            array_map(fn ($name) => $key[$name], $primary),
        ];
    };

    $pdo->beginTransaction();

    try {
        foreach ($request['inserts'] ?? [] as $values) {
            $names = knownColumns((array) $values, $columns);
            $sql = $names === []
                ? "INSERT INTO {$from} ".($driver === 'mysql' ? '() VALUES ()' : 'DEFAULT VALUES')
                : "INSERT INTO {$from} (".implode(', ', array_map(fn ($name) => quote($name, $driver), $names)).') VALUES ('.implode(', ', array_fill(0, count($names), '?')).')';
            $pdo->prepare($sql)->execute(params((array) $values));
            $counts['inserted']++;
        }

        foreach ($request['updates'] ?? [] as $update) {
            $names = knownColumns((array) ($update['values'] ?? []), $columns);

            if ($names === []) {
                continue;
            }

            [$match, $keyBindings] = $keyClause($update['key'] ?? null);
            $statement = $pdo->prepare("UPDATE {$from} SET ".implode(', ', array_map(fn ($name) => quote($name, $driver).' = ?', $names))." WHERE {$match}");
            $statement->execute(params([...array_values((array) $update['values']), ...$keyBindings]));
            $counts['updated'] += $statement->rowCount();
        }

        foreach ($request['deletes'] ?? [] as $key) {
            [$match, $keyBindings] = $keyClause($key);
            $statement = $pdo->prepare("DELETE FROM {$from} WHERE {$match}");
            $statement->execute(params($keyBindings));
            $counts['deleted'] += $statement->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }

    return $counts;
}

/**
 * Run one SQL statement. Returns its rows (up to a limit) or how many rows it changed.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function query(PDO $pdo, array $request): array
{
    $sql = trim((string) ($request['sql'] ?? ''));

    if ($sql === '') {
        throw new ToolError('Write a SQL statement to run.');
    }

    $started = hrtime(true);
    $statement = $pdo->query($sql);
    $columns = [];

    for ($i = 0; $i < $statement->columnCount(); $i++) {
        $columns[] = $statement->getColumnMeta($i)['name'] ?? "column_{$i}";
    }

    $rows = [];

    if ($columns !== []) {
        while (count($rows) <= QUERY_ROWS_MAX && ($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $rows[] = array_map(cell(...), $row);
        }
    }

    return [
        'columns' => $columns,
        'rows' => array_slice($rows, 0, QUERY_ROWS_MAX),
        'truncated' => count($rows) > QUERY_ROWS_MAX,
        'affected' => $columns === [] ? $statement->rowCount() : null,
        'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
    ];
}

/**
 * A consistent copy of a SQLite database (VACUUM INTO, safe while the app writes), uploaded to a signed link, for
 * downloading it (HOST-007).
 *
 * @param  array<string, mixed>  $request
 * @return array{bytes: int}
 */
function backup(PDO $pdo, string $driver, array $request): array
{
    if ($driver !== 'sqlite') {
        throw new ToolError('Only SQLite databases can be downloaded.');
    }

    $url = (string) ($request['upload_url'] ?? '');

    if (! preg_match('#^https?://#', $url)) {
        throw new ToolError('No address to upload the copy to.');
    }

    $copy = sys_get_temp_dir().'/onedrop-db-'.bin2hex(random_bytes(6)).'.sqlite';

    try {
        $pdo->exec('VACUUM INTO '.$pdo->quote($copy));
        $command = ['curl', '-fsS', '--retry', '3', '-X', 'PUT', '-T', $copy];

        foreach ((array) ($request['upload_headers'] ?? []) as $name => $value) {
            array_push($command, '-H', $name.': '.(is_array($value) ? implode(', ', $value) : $value));
        }

        $command[] = $url;
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new ToolError("Couldn't upload the copy: ".trim(implode(' ', $output)));
        }

        return ['bytes' => (int) filesize($copy)];
    } finally {
        @unlink($copy);
    }
}

// auth.php includes this file for its helpers; only run a request when called directly.
if (! defined('APP_DB_LIBRARY')) {
    try {
        $raw = getenv('APP_DB_REQUEST');
        $request = json_decode($raw === false || $raw === '' ? (string) stream_get_contents(STDIN) : $raw, true);

        if (! is_array($request)) {
            throw new ToolError('The request was not valid JSON.');
        }

        $op = $request['op'] ?? null;

        if ($op === 'connections') {
            respond(['ok' => true, 'data' => array_map(describe(...), connections())]);
        }

        if (! in_array($op, ['tables', 'rows', 'changes', 'query', 'backup'], true)) {
            throw new ToolError('Unknown operation.');
        }

        [$pdo, $driver] = open((string) ($request['connection'] ?? ''));

        respond(['ok' => true, 'data' => match ($op) {
            'tables' => tablesWithColumns($pdo, $driver),
            'rows' => rows($pdo, $driver, $request),
            'changes' => changes($pdo, $driver, $request),
            'query' => query($pdo, $request),
            'backup' => backup($pdo, $driver, $request),
        }]);
    } catch (ToolError|PDOException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()]);
    }
}
