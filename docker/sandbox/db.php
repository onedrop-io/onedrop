<?php

/**
 * Database tool behind the workspace's Tools → Database panel.
 *
 * Finds the app's databases (from .env files, SQLite files in the workspace and database containers in the
 * sandbox's own Docker), then browses, edits or queries one of them: SQLite, Postgres and MySQL through PDO,
 * MongoDB through the mongodb extension (DB-002). Connection details and passwords never leave the sandbox:
 * the platform only ever sees connection ids.
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
use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\AuthenticationException;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\ExecutionTimeoutException;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const PAGE_SIZE_MAX = 100;
const QUERY_ROWS_MAX = 500;
const CELL_BYTES_MAX = 10_000;
const SQLITE_SCAN_DEPTH = 4;
const SKIPPED_DIRS = ['node_modules', 'vendor', '.git', '.cache', '.onedrop', 'storage/framework'];
const FILTER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'null', 'notnull'];
const MONGO_SYSTEM_DATABASES = ['admin', 'config', 'local'];
/** Collections sampled for their field names when listing (the SQL runner's autocomplete); the rest list none. */
const MONGO_SAMPLED_COLLECTIONS = 200;
/** How documents are read: top-level fields as an array, nested documents as objects, so {} and [] stay apart. */
const MONGO_TYPE_MAP = ['root' => 'array', 'document' => 'object', 'array' => 'array'];

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

    // MongoDB URLs can list several hosts (a replica set), which parse_url can't read.
    if (preg_match('#^(mongodb(?:\+srv)?)://(?:([^@/]*)@)?([^/?]+)(?:/([^?]*))?(?:\?(.*))?$#i', $url, $m)) {
        [$user, $password] = $m[2] !== '' ? array_pad(explode(':', $m[2], 2), 2, null) : [null, null];
        parse_str($m[5] ?? '', $options);

        return mongoConnection(
            strtolower($m[1]),
            $user === null ? null : rawurldecode($user),
            $password === null ? null : rawurldecode($password),
            $m[3],
            rawurldecode($m[4] ?? ''),
            array_filter($options, 'is_string'),
        );
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
    if ($driver === 'mongodb') {
        return mongoConnection('mongodb', $user ?: null, $user ? $password : null, $host.':'.($port ?? 27017), $database, []);
    }

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
 * A MongoDB connection (DB-002). Without a database it covers every database on the server. A single host is
 * reached directly, since a replica set's member names (mongo:27017) often resolve only inside its compose network.
 *
 * @param  array<string, string>  $options
 * @return array<string, mixed>
 */
function mongoConnection(string $scheme, ?string $user, ?string $password, string $hosts, string $database, array $options): array
{
    $single = $scheme === 'mongodb' && ! str_contains($hosts, ',');
    $lower = array_change_key_case($options);

    if ($single && ! isset($lower['directconnection'])) {
        $options['directConnection'] = 'true';
    }

    // A user with no database in the URL signs in against admin, as MongoDB itself does.
    if ($user !== null && $database === '' && ! isset($lower['authsource'])) {
        $options['authSource'] = 'admin';
    }

    $auth = $user === null ? '' : rawurlencode($user).($password === null ? '' : ':'.rawurlencode($password)).'@';
    $query = http_build_query($options, '', '&', PHP_QUERY_RFC3986);
    [$host, $port] = $single && preg_match('/^(.+?)(?::(\d+))?$/', $hosts, $m) ? [$m[1], (int) ($m[2] ?? 27017)] : [null, null];

    return [
        'driver' => 'mongodb',
        'summary' => ($user ? "{$user}@" : '').$hosts.($database !== '' ? "/{$database}" : ''),
        'uri' => "{$scheme}://{$auth}{$hosts}/".rawurlencode($database).($query !== '' ? "?{$query}" : ''),
        'database' => $database === '' ? null : $database,
        'user' => $user,
        'password' => $password,
        'mongo' => compact('scheme', 'hosts', 'options'),
        'server' => $host === null ? null : ['host' => $host, 'port' => $port, 'database' => $database, 'sslmode' => null],
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
            if (str_ends_with($key, '_FILE') && preg_match('/^(POSTGRES|POSTGRESQL|MYSQL|MARIADB|MONGO_INITDB|MONGODB)_/', $base) && ! isset($env[$base])) {
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

        if ($kind === 'mongodb') {
            // The official image's root user (or Bitnami's), signed in against admin; every database is listed.
            $user = $env['MONGO_INITDB_ROOT_USERNAME'] ?? $env['MONGODB_ROOT_USER'] ?? (isset($env['MONGODB_ROOT_PASSWORD']) ? 'root' : null);
            $password = $env['MONGO_INITDB_ROOT_PASSWORD'] ?? $env['MONGODB_ROOT_PASSWORD'] ?? null;
            $port = (int) ($env['MONGODB_PORT_NUMBER'] ?? 27017);
            [$host, $hostPort] = $address($port);
            $label = $service ?? $name;

            $found[] = [
                'connection' => [
                    ...mongoConnection('mongodb', $user, $password, "{$host}:{$hostPort}", '', []),
                    'id' => "docker:{$name}",
                    'summary' => ($user ? "{$user}@" : '')."{$label}:{$port}",
                    'source' => "Docker container {$name}",
                ],
                'names' => array_values(array_unique($names)),
                'address' => $address,
            ];

            continue;
        }

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
 * 'pgsql', 'mysql' or 'mongodb' for an image built on an official database image (FROM postgres:16 in the project's own
 * Dockerfile), which keeps the version variables the official image sets, else null.
 *
 * @param  array<string, string>  $env
 */
function databaseImageEnv(array $env): ?string
{
    return match (true) {
        isset($env['PG_MAJOR']) || isset($env['PG_VERSION']) => 'pgsql',
        isset($env['MARIADB_VERSION']) || isset($env['MYSQL_MAJOR']) || isset($env['MYSQL_VERSION']) => 'mysql',
        isset($env['MONGO_MAJOR']) || isset($env['MONGO_VERSION']) => 'mongodb',
        default => null,
    };
}

/**
 * 'pgsql', 'mysql' or 'mongodb' for a database server's image (postgres:16, supabase/postgres:15.8, mariadb:11, mongo:8, …),
 * else null.
 */
function databaseImage(string $image): ?string
{
    $repository = strtolower(basename((string) preg_replace('/(@.*|:[^\/]*)$/', '', $image)));

    return match (true) {
        in_array($repository, ['postgres', 'postgresql', 'postgis', 'pgvector', 'timescaledb', 'timescaledb-ha'], true) => 'pgsql',
        in_array($repository, ['mysql', 'mysql-server', 'mariadb', 'percona-server'], true) => 'mysql',
        in_array($repository, ['mongo', 'mongodb', 'mongodb-community-server', 'mongodb-enterprise-server', 'percona-server-mongodb'], true) => 'mongodb',
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
    $named = ['DATABASE_URL', 'DB_URL', 'POSTGRES_URL', 'MYSQL_URL', 'MONGODB_URI', 'MONGODB_URL', 'MONGO_URI', 'MONGO_URL', 'DB_URI'];
    $keys = [...array_intersect($named, array_keys($env)), ...array_filter(
        array_diff(array_keys($env), $named),
        fn (string $key) => preg_match('#^(postgres(ql)?|mysql|mariadb|mongodb(\+srv)?)://#i', $env[$key]),
    )];
    // The database a MongoDB URL without one is used with (Laravel MongoDB's MONGODB_DATABASE, and the like).
    $mongoDatabase = $env['MONGODB_DATABASE'] ?? $env['MONGO_DATABASE'] ?? $env['MONGO_DB'] ?? $env['MONGO_DB_NAME'] ?? $env['MONGODB_DB'] ?? null;

    foreach ($keys as $key) {
        if ($env[$key] !== '' && ! isset($urls[$env[$key]]) && ($connection = fromUrl($env[$key], $dir))) {
            if ($connection['driver'] === 'mongodb' && $connection['database'] === null && $mongoDatabase) {
                ['scheme' => $scheme, 'hosts' => $hosts, 'options' => $options] = $connection['mongo'];
                $connection = mongoConnection($scheme, $connection['user'], $connection['password'], $hosts, $mongoDatabase, $options);
            }

            $urls[$env[$key]] = true;
            $found[] = $connection + ['id' => "env:{$source}:{$key}", 'source' => "{$source} ({$key})"];
        }
    }

    // A SQL database URL is the app's database; a MongoDB one may sit beside the separate settings of another.
    if (array_filter($found, fn (array $connection) => $connection['driver'] !== 'mongodb') !== []) {
        return $found;
    }

    $driver = strtolower($env['DB_CONNECTION'] ?? $env['DB_DRIVER'] ?? $env['DB_TYPE'] ?? $env['DB_DIALECT'] ?? $env['DB_CLIENT'] ?? '');

    if ($driver === 'sqlite') {
        $database = $env['DB_DATABASE'] ?? '';
        $path = $database === '' || $database === ':memory:' ? 'database/database.sqlite' : $database;

        return [...$found, sqliteConnection(resolvePath($path, $dir)) + ['source' => "{$source} (DB_CONNECTION)"]];
    }

    $driver = match (true) {
        in_array($driver, ['pgsql', 'postgres', 'postgresql', 'pg'], true) => 'pgsql',
        in_array($driver, ['mysql', 'mysql2', 'mariadb'], true) => 'mysql',
        // A MongoDB URL already found is the one Laravel MongoDB's DB_CONNECTION=mongodb uses.
        in_array($driver, ['mongodb', 'mongo'], true) => $found === [] ? 'mongodb' : null,
        $driver === '' && isset($env['DB_HOST']) => ['5432' => 'pgsql', '3306' => 'mysql', '27017' => 'mongodb'][$env['DB_PORT'] ?? ''] ?? null,
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
        [$found === [] ? 'mongodb' : null, isset($env['MONGODB_HOST']) ? 'MONGODB_HOST' : 'MONGO_HOST', $env['MONGODB_HOST'] ?? $env['MONGO_HOST'] ?? null, $env['MONGODB_PORT'] ?? $env['MONGO_PORT'] ?? null,
            $mongoDatabase ?? '', $env['MONGODB_USERNAME'] ?? $env['MONGO_USERNAME'] ?? $env['MONGO_USER'] ?? null, $env['MONGODB_PASSWORD'] ?? $env['MONGO_PASSWORD'] ?? null],
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
                        $connection['driver'] === 'mongodb'
                            ? mongoConnection('mongodb', $connection['user'], $connection['password'], "{$host}:{$port}", $server['database'], $connection['mongo']['options'])
                            : serverConnection($connection['driver'], $host, $port, $server['database'], $connection['user'], $connection['password'], $server['sslmode']),
                        array_flip(['dsn', 'uri', 'server']),
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
    return [
        'id' => $connection['id'],
        'driver' => $connection['driver'],
        'label' => ['sqlite' => 'SQLite', 'pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB'][$connection['driver']],
        'summary' => $connection['summary'],
        'source' => $connection['source'] ?? null,
        'error' => unusable($connection),
    ];
}

/**
 * Why a connection can't be opened before even trying, or null.
 *
 * @param  array<string, mixed>  $connection
 */
function unusable(array $connection): ?string
{
    return match (true) {
        $connection['driver'] === 'sqlite' && ! is_file($connection['path']) => "The file {$connection['summary']} doesn't exist yet.",
        $connection['driver'] === 'mongodb' && ! extension_loaded('mongodb') => "This sandbox can't open MongoDB yet. Rebuild the sandbox image and update the sandbox.",
        default => null,
    };
}

/**
 * @return array{0: PDO|Mongo, 1: string}
 */
function open(string $id): array
{
    foreach (connections() as $connection) {
        if ($connection['id'] !== $id) {
            continue;
        }

        $driver = $connection['driver'];

        if (($problem = unusable($connection)) !== null) {
            throw new ToolError($problem);
        }

        if ($driver === 'mongodb') {
            return [new Mongo(new Manager($connection['uri'], ['serverSelectionTimeoutMS' => 5000, 'connectTimeoutMS' => 5000, 'appname' => 'onedrop']), $connection['database']), $driver];
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

/**
 * An open MongoDB connection (DB-002): the server, and the database it names (null: every database on it).
 */
final class Mongo
{
    public function __construct(public Manager $manager, public ?string $database) {}

    /**
     * Run a database command; its documents (a cursor's, or the one reply).
     *
     * @param  array<string, mixed>|stdClass  $command
     * @return list<array<string, mixed>>
     */
    public function command(string $database, array|stdClass $command, int $max = PHP_INT_MAX): array
    {
        $cursor = $this->manager->executeCommand($database, new Command($command));
        $cursor->setTypeMap(MONGO_TYPE_MAP);
        $documents = [];

        foreach ($cursor as $document) {
            if (count($documents) >= $max) {
                break;
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>|stdClass  $filter
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function find(string $database, string $collection, array|stdClass $filter, array $options = []): array
    {
        $cursor = $this->manager->executeQuery("{$database}.{$collection}", new Query($filter, $options));
        $cursor->setTypeMap(MONGO_TYPE_MAP);

        return $cursor->toArray();
    }

    /**
     * Databases to browse: the one the connection names, or every one on the server but MongoDB's own.
     *
     * @return list<string>
     */
    public function databases(): array
    {
        if ($this->database !== null) {
            return [$this->database];
        }

        $names = $this->command('admin', ['listDatabases' => 1, 'nameOnly' => true])[0]['databases'] ?? [];
        $names = array_values(array_diff(array_map(fn ($database) => $database->name, $names), MONGO_SYSTEM_DATABASES));
        sort($names);

        return $names;
    }

    /**
     * The database commands run in when none is chosen: the connection's, or the server's only one.
     */
    public function defaultDatabase(): ?string
    {
        if ($this->database !== null) {
            return $this->database;
        }

        $databases = $this->databases();

        return count($databases) === 1 ? $databases[0] : null;
    }

    /**
     * Collections and views (not system.*) of one database.
     *
     * @return list<array{name: string, type: string}>
     */
    public function collections(string $database): array
    {
        $collections = [];

        foreach ($this->command($database, ['listCollections' => 1, 'authorizedCollections' => true]) as $info) {
            if (! str_starts_with($info['name'], 'system.')) {
                $collections[] = ['name' => $info['name'], 'type' => ($info['type'] ?? 'collection') === 'view' ? 'view' : 'table'];
            }
        }

        usort($collections, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $collections;
    }
}

/**
 * Collections of every database the connection covers, named "database.collection" when it covers several, with the
 * field names of a few documents each (for the query runner's autocomplete).
 *
 * @return list<array{name: string, type: string, columns: list<string>, database: string, collection: string}>
 */
function mongoTables(Mongo $mongo): array
{
    $tables = [];

    foreach ($mongo->databases() as $database) {
        foreach ($mongo->collections($database) as $collection) {
            $tables[] = [
                'name' => $mongo->database === null ? "{$database}.{$collection['name']}" : $collection['name'],
                'type' => $collection['type'],
                'columns' => count($tables) < MONGO_SAMPLED_COLLECTIONS && $collection['type'] === 'table' ? mongoSampleFields($mongo, $database, $collection['name']) : [],
                'database' => $database,
                'collection' => $collection['name'],
            ];
        }
    }

    return $tables;
}

/**
 * Field names of a few of a collection's documents, or none when reading them is slow. Views aren't sampled: each
 * read runs the view's pipeline, which can take seconds.
 *
 * @return list<string>
 */
function mongoSampleFields(Mongo $mongo, string $database, string $collection): array
{
    try {
        return mongoFields($mongo->find($database, $collection, [], ['limit' => 20, 'maxTimeMS' => 300]));
    } catch (ExecutionTimeoutException) {
        return [];
    }
}

/**
 * The database and collection a grid name means, checked to exist.
 *
 * @return array{0: string, 1: string, 2: string} database, collection, 'table' or 'view'
 */
function mongoCollection(Mongo $mongo, mixed $name): array
{
    $name = (string) $name;
    [$database, $collection] = $mongo->database !== null ? [$mongo->database, $name] : array_pad(explode('.', $name, 2), 2, '');

    if ($database !== '' && $collection !== '') {
        foreach ($mongo->collections($database) as $found) {
            if ($found['name'] === $collection) {
                return [$database, $collection, $found['type']];
            }
        }
    }

    throw new ToolError("The collection {$name} doesn't exist.");
}

/**
 * Top-level field names of documents, in the order they first appear, _id first.
 *
 * @param  list<array<string, mixed>>  $documents
 * @return list<string>
 */
function mongoFields(array $documents): array
{
    $fields = [];

    foreach ($documents as $document) {
        foreach (array_keys($document) as $field) {
            $fields[(string) $field] = true;
        }
    }

    $fields = array_keys($fields);

    return in_array('_id', $fields, true) ? ['_id', ...array_values(array_diff($fields, ['_id']))] : $fields;
}

/**
 * A BSON value's type name, as MongoDB's $type calls it.
 */
function mongoType(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => 'bool',
        is_int($value) => $value >= -2147483648 && $value <= 2147483647 ? 'int' : 'long',
        is_float($value) => 'double',
        is_string($value) => 'string',
        is_array($value) => 'array',
        $value instanceof stdClass => 'object',
        $value instanceof ObjectId => 'objectId',
        $value instanceof UTCDateTime => 'date',
        $value instanceof Decimal128 => 'decimal',
        $value instanceof Int64 => 'long',
        $value instanceof Binary => mongoUuid($value) !== null ? 'uuid' : 'binData',
        $value instanceof Regex => 'regex',
        $value instanceof Timestamp => 'timestamp',
        default => lcfirst(substr(strrchr(get_class($value), '\\') ?: get_class($value), 1)),
    };
}

function mongoUuid(Binary $value): ?string
{
    return in_array($value->getType(), [Binary::TYPE_UUID, Binary::TYPE_OLD_UUID], true) && strlen($value->getData()) === 16
        ? vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($value->getData()), 4))
        : null;
}

/**
 * A value as the grid shows it: text for ObjectIds, dates (ISO 8601), decimals and integers JavaScript can't hold,
 * relaxed Extended JSON for objects and arrays, a preview for binary data.
 */
function mongoCell(mixed $value): mixed
{
    return match (true) {
        $value === null, is_bool($value), is_string($value) => cell($value),
        is_int($value) => abs($value) > 9007199254740991 ? (string) $value : $value,
        is_float($value) => is_finite($value) ? $value : (string) $value,
        is_array($value), $value instanceof stdClass => cell(mongoJson($value)),
        $value instanceof UTCDateTime => $value->toDateTime()->format('Y-m-d\TH:i:s.v\Z'),
        $value instanceof Binary => mongoUuid($value) ?? ['preview' => null, 'bytes' => strlen($value->getData())],
        $value instanceof Regex => "/{$value->getPattern()}/{$value->getFlags()}",
        $value instanceof Stringable => (string) $value,
        default => mongoJson($value),
    };
}

/**
 * A value as relaxed Extended JSON ({"$oid": …} for an ObjectId inside an object).
 */
function mongoJson(mixed $value): string
{
    $json = json_decode(Document::fromPHP(['v' => $value])->toRelaxedExtendedJSON());

    return (string) json_encode($json->v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Documents as a result table: their fields as columns, values as the grid shows them, missing fields as null.
 *
 * @param  list<array<string, mixed>>  $documents
 * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool, affected: null}
 */
function mongoResult(array $documents): array
{
    $truncated = count($documents) > QUERY_ROWS_MAX;
    $documents = array_slice($documents, 0, QUERY_ROWS_MAX);
    $columns = mongoFields($documents);

    return [
        'columns' => $columns,
        'rows' => array_map(fn (array $document) => array_map(fn (string $field) => array_key_exists($field, $document) ? mongoCell($document[$field]) : null, $columns), $documents),
        'truncated' => $truncated,
        'affected' => null,
    ];
}

/**
 * Every value a filter's text could mean: the text itself, and the number, boolean, ObjectId, UUID or date it
 * reads as, so "42" finds 42 stored either way.
 *
 * @return list<mixed>
 */
function mongoCandidates(string $text, bool $booleans = true): array
{
    $trimmed = trim($text);
    $candidates = [$text];

    if (preg_match('/^-?\d{1,18}$/', $trimmed)) {
        $candidates[] = (int) $trimmed;
    } elseif (is_numeric($trimmed)) {
        $candidates[] = (float) $trimmed;
    }

    if ($booleans && in_array(strtolower($trimmed), ['true', 'false'], true)) {
        $candidates[] = strtolower($trimmed) === 'true';
    }

    if (preg_match('/^[0-9a-f]{24}$/i', $trimmed)) {
        $candidates[] = new ObjectId($trimmed);
    }

    if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $trimmed)) {
        $candidates[] = new Binary((string) hex2bin(str_replace('-', '', $trimmed)), Binary::TYPE_UUID);
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $trimmed) && ($date = mongoDate($trimmed)) !== null) {
        $candidates[] = $date;
    }

    return $candidates;
}

function mongoDate(string $text): ?UTCDateTime
{
    $text = trim($text);

    if (preg_match('/^-?\d+$/', $text)) {
        return new UTCDateTime((int) $text);
    }

    try {
        return new UTCDateTime(new DateTimeImmutable($text, new DateTimeZone('UTC')));
    } catch (Exception) {
        return null;
    }
}

/**
 * A field name the grid may use: not empty, not an operator.
 */
function mongoField(mixed $name): string
{
    if (! is_string($name) || $name === '' || $name[0] === '$' || str_contains($name, "\0")) {
        throw new ToolError('That field name is not valid.');
    }

    return $name;
}

/**
 * A query filter from the grid's filters.
 *
 * @param  list<array{column?: mixed, operator?: mixed, value?: mixed}>  $filters
 * @return array<string, mixed>
 */
function mongoFilter(array $filters): array
{
    $clauses = [];

    foreach ($filters as $filter) {
        $operator = $filter['operator'] ?? 'eq';

        if (! in_array($operator, FILTER_OPERATORS, true)) {
            throw new ToolError('That filter is not valid.');
        }

        $field = mongoField($filter['column'] ?? null);
        $value = (string) ($filter['value'] ?? '');

        $clauses[] = match ($operator) {
            'null' => [$field => null],
            'notnull' => [$field => ['$ne' => null]],
            'contains' => [$field => new Regex(preg_quote($value), 'i')],
            'eq' => [$field => ['$in' => mongoCandidates($value)]],
            'neq' => [$field => ['$nin' => mongoCandidates($value)]],
            // Comparisons only match values of the same kind, so each kind the text could be is tried.
            default => ['$or' => array_map(
                fn ($candidate) => [$field => ['$'.$operator => $candidate]],
                mongoCandidates($value, booleans: false),
            )],
        };
    }

    return $clauses === [] ? [] : ['$and' => $clauses];
}

/**
 * One page of a collection's documents, with their fields as columns.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function mongoRows(Mongo $mongo, array $request): array
{
    [$database, $collection, $type] = mongoCollection($mongo, $request['table'] ?? null);
    $filter = mongoFilter(is_array($request['filters'] ?? null) ? $request['filters'] : []);
    $perPage = max(1, min(PAGE_SIZE_MAX, (int) ($request['per_page'] ?? 50)));
    $page = max(1, (int) ($request['page'] ?? 1));
    $sort = isset($request['sort']) ? mongoField($request['sort']) : null;
    $direction = ($request['direction'] ?? 'asc') === 'desc' ? -1 : 1;

    // _id last, so pages stay stable when the sorted field repeats. Views may have no _id, and keep their own order.
    $order = $sort !== null ? [$sort => $direction] + ($sort !== '_id' && $type === 'table' ? ['_id' => 1] : []) : ($type === 'table' ? ['_id' => 1] : []);
    $documents = $mongo->find($database, $collection, (object) $filter, ['sort' => (object) $order, 'skip' => ($page - 1) * $perPage, 'limit' => $perPage]);
    $total = $mongo->command($database, ['aggregate' => $collection, 'pipeline' => [['$match' => (object) $filter], ['$count' => 'n']], 'cursor' => new stdClass])[0]['n'] ?? 0;

    // An empty page still shows the collection's fields.
    $fields = mongoFields($documents ?: $mongo->find($database, $collection, [], ['limit' => 20]));
    $types = [];

    foreach ($documents as $document) {
        foreach ($document as $field => $value) {
            if ($value !== null) {
                $types[$field][mongoType($value)] = true;
            }
        }
    }

    return [
        'table' => (string) $request['table'],
        'type' => $type,
        'columns' => array_map(fn (string $field) => [
            'name' => $field,
            'type' => implode(' | ', array_slice(array_keys($types[$field] ?? ['null' => true]), 0, 3)),
            'nullable' => $field !== '_id',
            'default' => null,
            'primary' => $field === '_id',
            'auto' => $field === '_id',
        ], $fields),
        // Missing fields are left out, so the grid can tell them from null.
        'rows' => array_map(fn (array $document) => array_map(mongoCell(...), $document), $documents),
        'total' => (int) $total,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

/**
 * What the grid's text means for a field of the given type: the same type when it has one (an ObjectId stays an
 * ObjectId), else what the text reads as (a number, true/false, a JSON object or array, else text).
 */
function mongoValue(mixed $input, ?string $type, string $field): mixed
{
    if ($input === null) {
        return null;
    }

    $text = is_bool($input) ? ($input ? 'true' : 'false') : (string) $input;
    $trimmed = trim($text);
    $invalid = fn (string $what) => new ToolError("{$field}: \"".mb_strimwidth($text, 0, 40, '…')."\" isn't {$what}.");

    try {
        return match ($type) {
            'string' => $text,
            'int' => preg_match('/^-?\d+$/', $trimmed) ? (int) $trimmed : throw $invalid('a whole number'),
            'long' => preg_match('/^-?\d+$/', $trimmed) ? new Int64($trimmed) : throw $invalid('a whole number'),
            'double' => is_numeric($trimmed) ? (float) $trimmed : throw $invalid('a number'),
            'decimal' => is_numeric($trimmed) ? new Decimal128($trimmed) : throw $invalid('a number'),
            'bool' => match (strtolower($trimmed)) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw $invalid('true or false'),
            },
            'objectId' => preg_match('/^[0-9a-f]{24}$/i', $trimmed) ? new ObjectId($trimmed) : throw $invalid('an ObjectId (24 hex characters)'),
            'date' => mongoDate($trimmed) ?? throw $invalid('a date'),
            'uuid' => preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $trimmed)
                ? new Binary((string) hex2bin(str_replace('-', '', $trimmed)), Binary::TYPE_UUID)
                : throw $invalid('a UUID'),
            'object', 'array' => (function () use ($text, $type, $invalid) {
                try {
                    $value = mongoBson(MongoSyntax::value($text));
                } catch (ToolError) {
                    throw $invalid("a JSON {$type}");
                }

                return ($type === 'object' ? $value instanceof stdClass : is_array($value)) ? $value : throw $invalid("a JSON {$type}");
            })(),
            null, 'null' => (function () use ($text) {
                try {
                    return mongoBson(MongoSyntax::value($text));
                } catch (ToolError|InvalidArgumentException) {
                    return $text;
                }
            })(),
            default => throw new ToolError("{$field} holds a {$type}, which can't be edited here. Use the query runner."),
        };
    } catch (InvalidArgumentException) {
        throw $invalid("a valid {$type}");
    }
}

/**
 * Insert, update and delete documents of one collection: in one transaction on a replica set; in order on a
 * standalone server (which has no transactions), stopping at the first failure. Documents are found by _id, and
 * each value keeps the type its field has.
 *
 * @param  array<string, mixed>  $request
 * @return array{inserted: int, updated: int, deleted: int}
 */
function mongoChanges(Mongo $mongo, array $request): array
{
    [$database, $collection, $type] = mongoCollection($mongo, $request['table'] ?? null);

    if ($type === 'view') {
        throw new ToolError("{$request['table']} is a view, so it can't be edited.");
    }

    $bulk = new BulkWrite(['ordered' => true]);
    $knownTypes = [];

    // The type a field has elsewhere in the collection, for documents that don't have it yet.
    $typeInCollection = function (string $field) use ($mongo, $database, $collection, &$knownTypes): ?string {
        if (! array_key_exists($field, $knownTypes)) {
            $found = $mongo->find($database, $collection, [$field => ['$exists' => true, '$ne' => null]], ['limit' => 1, 'projection' => [$field => 1]]);
            $knownTypes[$field] = $found !== [] && array_key_exists($field, $found[0]) ? mongoType($found[0][$field]) : null;
        }

        return $knownTypes[$field];
    };

    // The stored document a key names (its _id given as text, a number or a JSON value), or null when it's gone.
    $stored = function (mixed $key) use ($mongo, $database, $collection): ?array {
        if (! is_array($key) || array_keys($key) !== ['_id']) {
            throw new ToolError('Each changed document must be identified by its _id.');
        }

        $id = $key['_id'];
        $candidates = is_string($id) ? [...mongoCandidates($id), ...(str_starts_with(trim($id), '{') ? [mongoValue($id, 'object', '_id')] : [])] : [$id];

        return $mongo->find($database, $collection, ['_id' => ['$in' => $candidates]], ['limit' => 1])[0] ?? null;
    };

    $values = function (mixed $values, ?array $document) use ($typeInCollection): array {
        $converted = [];

        foreach ((array) $values as $field => $value) {
            $field = mongoField((string) $field);
            $type = $document !== null && array_key_exists($field, $document) ? mongoType($document[$field]) : $typeInCollection($field);
            $converted[$field] = mongoValue($value, $type === 'null' ? null : $type, $field);
        }

        return $converted;
    };

    $writes = 0;

    foreach ($request['inserts'] ?? [] as $insert) {
        $bulk->insert((object) $values($insert, null));
        $writes++;
    }

    foreach ($request['updates'] ?? [] as $update) {
        if (($document = $stored($update['key'] ?? null)) !== null && ($set = $values($update['values'] ?? [], $document)) !== []) {
            unset($set['_id']);
            $bulk->update(['_id' => $document['_id']], ['$set' => (object) $set]);
            $writes++;
        }
    }

    foreach ($request['deletes'] ?? [] as $key) {
        if (($document = $stored($key)) !== null) {
            $bulk->delete(['_id' => $document['_id']], ['limit' => 1]);
            $writes++;
        }
    }

    if ($writes === 0) {
        return ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
    }

    $hello = $mongo->command('admin', ['hello' => 1])[0] ?? [];
    $transactions = isset($hello['setName']) || ($hello['msg'] ?? null) === 'isdbgrid';
    $namespace = "{$database}.{$collection}";

    try {
        if ($transactions) {
            $session = $mongo->manager->startSession();
            $session->startTransaction();

            try {
                $result = $mongo->manager->executeBulkWrite($namespace, $bulk, ['session' => $session]);
                $session->commitTransaction();
            } catch (Throwable $e) {
                if ($session->isInTransaction()) {
                    $session->abortTransaction();
                }

                throw $e;
            }
        } else {
            $result = $mongo->manager->executeBulkWrite($namespace, $bulk);
        }
    } catch (BulkWriteException $e) {
        $error = $e->getWriteResult()->getWriteErrors()[0] ?? null;
        $message = $error?->getMessage() ?? $e->getMessage();

        $saved = $error?->getIndex() ?? 0;

        throw new ToolError($transactions || $error === null ? $message : rtrim($message, '.').'. This MongoDB server has no transactions, so '.match ($saved) {
            0 => 'nothing was saved.',
            1 => 'the change before it was saved.',
            default => "the {$saved} changes before it were saved.",
        });
    }

    return ['inserted' => $result->getInsertedCount(), 'updated' => $result->getMatchedCount(), 'deleted' => $result->getDeletedCount()];
}

/**
 * Run one mongosh-style command (DB-002): db.<collection>.<method>(…) with cursor methods chained on, db.runCommand,
 * show dbs / collections, or a bare {…} command; `use <database>` lines before it pick the database.
 *
 * @param  array<string, mixed>  $request
 * @return array<string, mixed>
 */
function mongoQuery(Mongo $mongo, array $request): array
{
    $text = trim((string) ($request['sql'] ?? ''));
    $database = $mongo->database;

    while (preg_match('/^use\s+([^\s;]+)\s*;?\s*/', $text, $match)) {
        $database = $match[1];
        $text = substr($text, strlen($match[0]));
    }

    $text = rtrim(trim($text), ';');

    if ($text === '') {
        throw new ToolError('Write a command to run, like db.users.find().');
    }

    $database ??= $mongo->defaultDatabase();
    $inDatabase = fn (): string => $database ?? throw new ToolError('Pick a database first: put "use <database>" on the line before.');
    $started = hrtime(true);
    $result = mongoRun($mongo, $text, $inDatabase);

    return [...$result, 'duration_ms' => round((hrtime(true) - $started) / 1e6, 1)];
}

/**
 * @param  callable(): string  $inDatabase  the database to run in (asks for one when none is chosen)
 * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool, affected: ?int}
 */
function mongoRun(Mongo $mongo, string $text, callable $inDatabase): array
{
    $affected = fn (int $count) => ['columns' => [], 'rows' => [], 'truncated' => false, 'affected' => $count];

    if (preg_match('/^show\s+(dbs|databases)$/i', $text)) {
        $databases = $mongo->command('admin', ['listDatabases' => 1])[0]['databases'] ?? [];

        return mongoResult(array_map(fn ($database) => ['name' => $database->name, 'sizeOnDisk' => $database->sizeOnDisk ?? null, 'empty' => $database->empty ?? null], $databases));
    }

    if (preg_match('/^show\s+(collections|tables)$/i', $text)) {
        return mongoResult(array_map(fn ($collection) => ['name' => $collection['name'], 'type' => $collection['type'] === 'view' ? 'view' : 'collection'], $mongo->collections($inDatabase())));
    }

    // A bare command document, as db.runCommand takes.
    if ($text[0] === '{') {
        return mongoResult($mongo->command($inDatabase(), mongoBson(MongoSyntax::value($text)), QUERY_ROWS_MAX + 1));
    }

    $steps = MongoSyntax::chain($text);
    $call = function () use (&$steps): ?array {
        if (($steps[0][0] ?? null) === 'prop' && ($steps[1][0] ?? null) === 'call') {
            [[, $name], [, $arguments]] = array_splice($steps, 0, 2);

            return [$name, array_map(mongoBson(...), $arguments)];
        }

        return null;
    };
    $database = null;

    if (($steps[0][1] ?? null) === 'getSiblingDB') {
        [, $arguments] = $call();
        $database = is_string($arguments[0] ?? null) ? $arguments[0] : throw new ToolError('getSiblingDB takes a database name.');
    }

    // adminCommand always runs in admin; everything else in the chosen database.
    $database ??= ($steps[0][1] ?? null) === 'adminCommand' ? 'admin' : $inDatabase();

    if (in_array($steps[0][1] ?? null, ['runCommand', 'adminCommand', 'getCollectionNames'], true)) {
        [$name, $arguments] = $call() ?? throw new ToolError("db.{$steps[0][1]} needs to be called, like db.{$steps[0][1]}().");

        if ($steps !== []) {
            throw new ToolError("Nothing can follow db.{$name}().");
        }

        if ($name === 'getCollectionNames') {
            return mongoResult(array_map(fn ($collection) => ['name' => $collection['name']], $mongo->collections($database)));
        }

        $command = $arguments[0] ?? null;
        $command = is_string($command) ? [$command => 1] : $command;

        if (! $command instanceof stdClass && ! is_array($command)) {
            throw new ToolError("db.{$name} takes a command document, like db.{$name}({ ping: 1 }).");
        }

        return mongoResult($mongo->command($name === 'adminCommand' ? 'admin' : $database, $command, QUERY_ROWS_MAX + 1));
    }

    // The collection: db.users, db['my-users'] or db.getCollection('my-users').
    if (($steps[0][1] ?? null) === 'getCollection' && ($steps[1][0] ?? null) === 'call') {
        [, $arguments] = $call();
        $collection = is_string($arguments[0] ?? null) ? $arguments[0] : throw new ToolError('getCollection takes a collection name.');
    } elseif (($steps[0][0] ?? null) === 'prop' && ($steps[1][0] ?? null) !== 'call') {
        $collection = array_shift($steps)[1];
    } else {
        throw new ToolError('Name a collection, like db.users.find().');
    }

    [$method, $arguments] = $call() ?? throw new ToolError("Call a method on {$collection}, like db.{$collection}.find().");
    $document = fn (int $index, string $what) => match (true) {
        ! array_key_exists($index, $arguments) || $arguments[$index] === null => new stdClass,
        $arguments[$index] instanceof stdClass || (is_array($arguments[$index]) && $arguments[$index] === []) => (object) $arguments[$index],
        default => throw new ToolError("{$method}'s {$what} must be a document, like { name: 'Ada' }."),
    };
    $modifiers = [];

    while (($next = $call()) !== null) {
        $modifiers[] = $next;
    }

    if ($steps !== []) {
        throw new ToolError('Something after the command is missing its call, like .limit(10).');
    }

    $only = function (array $allowed) use ($modifiers, $method) {
        foreach ($modifiers as [$name]) {
            if (! in_array($name, $allowed, true)) {
                throw new ToolError(".{$name}() can't follow {$method}() here.");
            }
        }
    };
    $writes = fn (BulkWrite $bulk) => $mongo->manager->executeBulkWrite("{$database}.{$collection}", $bulk);
    $updateOptions = fn (int $index) => (array) ($arguments[$index] ?? []);

    switch ($method) {
        case 'find':
        case 'findOne':
            $options = ['projection' => $arguments[1] ?? null];
            $count = false;

            foreach ($modifiers as [$name, $modifierArguments]) {
                $value = $modifierArguments[0] ?? null;

                match ($name) {
                    'sort' => $options['sort'] = $value,
                    'limit' => $options['limit'] = (int) $value,
                    'skip' => $options['skip'] = (int) $value,
                    'projection', 'project' => $options['projection'] = $value,
                    'hint' => $options['hint'] = $value,
                    'collation' => $options['collation'] = $value,
                    'maxTimeMS' => $options['maxTimeMS'] = (int) $value,
                    'count', 'countDocuments', 'itcount', 'size' => $count = true,
                    'toArray', 'pretty' => null,
                    default => throw new ToolError(".{$name}() isn't supported after {$method}(). Use sort, skip, limit, projection, count or toArray."),
                };
            }

            $filter = $document(0, 'filter');

            if ($count) {
                $pipeline = [['$match' => $filter], ...(isset($options['skip']) ? [['$skip' => $options['skip']]] : []), ...(! empty($options['limit']) ? [['$limit' => $options['limit']]] : []), ['$count' => 'count']];

                return mongoResult([['count' => $mongo->command($database, ['aggregate' => $collection, 'pipeline' => $pipeline, 'cursor' => new stdClass])[0]['count'] ?? 0]]);
            }

            $limit = $method === 'findOne' ? 1 : (empty($options['limit']) ? QUERY_ROWS_MAX + 1 : min(abs($options['limit']), QUERY_ROWS_MAX + 1));

            return mongoResult($mongo->find($database, $collection, $filter, [...array_filter($options, fn ($value) => $value !== null), 'limit' => $limit]));

        case 'aggregate':
            $only(['toArray', 'pretty']);
            // aggregate([stages], options), or the stages as separate arguments.
            $pipeline = is_array($arguments[0] ?? null) ? $arguments[0] : $arguments;
            $options = is_array($arguments[0] ?? null) ? (array) ($arguments[1] ?? []) : [];

            return mongoResult($mongo->command($database, ['aggregate' => $collection, 'pipeline' => $pipeline, 'cursor' => new stdClass, ...$options], QUERY_ROWS_MAX + 1));

        case 'countDocuments':
        case 'count':
            $only([]);

            return mongoResult([['count' => $mongo->command($database, ['aggregate' => $collection, 'pipeline' => [['$match' => $document(0, 'filter')], ['$count' => 'count']], 'cursor' => new stdClass])[0]['count'] ?? 0]]);

        case 'estimatedDocumentCount':
            $only([]);

            return mongoResult([['count' => $mongo->command($database, ['count' => $collection])[0]['n'] ?? 0]]);

        case 'distinct':
            $only([]);
            $values = $mongo->command($database, ['distinct' => $collection, 'key' => (string) ($arguments[0] ?? ''), 'query' => $document(1, 'filter')])[0]['values'] ?? [];

            return mongoResult(array_map(fn ($value) => ['value' => $value], $values));

        case 'insertOne':
        case 'insertMany':
            $only([]);
            $documents = $method === 'insertOne' ? [$document(0, 'document')] : (is_array($arguments[0] ?? null) ? $arguments[0] : throw new ToolError('insertMany takes a list of documents.'));
            $bulk = new BulkWrite(['ordered' => true]);

            foreach ($documents as $index => $insert) {
                $bulk->insert($insert instanceof stdClass ? $insert : throw new ToolError("Document {$index} isn't a document."));
            }

            return $affected($writes($bulk)->getInsertedCount());

        case 'updateOne':
        case 'updateMany':
        case 'replaceOne':
            $only([]);
            $change = $arguments[1] ?? null;

            if (! $change instanceof stdClass && ! is_array($change)) {
                throw new ToolError("{$method} takes a filter and ".($method === 'replaceOne' ? 'a document' : 'an update').", like {$method}({ _id: 1 }, ".($method === 'replaceOne' ? '{ name: "Ada" }' : '{ $set: { name: "Ada" } }').').');
            }

            $bulk = new BulkWrite;
            $bulk->update($document(0, 'filter'), $change, ['multi' => $method === 'updateMany', 'upsert' => (bool) ($updateOptions(2)['upsert'] ?? false)]);
            $result = $writes($bulk);

            return $affected($result->getModifiedCount() + $result->getUpsertedCount());

        case 'deleteOne':
        case 'deleteMany':
            $only([]);
            $bulk = new BulkWrite;
            $bulk->delete($document(0, 'filter'), ['limit' => $method === 'deleteOne' ? 1 : 0]);

            return $affected($writes($bulk)->getDeletedCount());
    }

    throw new ToolError("{$method}() isn't supported here. Use find, findOne, aggregate, countDocuments, distinct, insertOne/Many, updateOne/Many, replaceOne, deleteOne/Many, or db.runCommand({ … }).");
}

/**
 * Extended JSON (as MongoSyntax reads it, or as typed: {"$oid": …}) turned into the extension's BSON values;
 * other keys, query operators included, are left as they are.
 */
function mongoBson(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map(mongoBson(...), $value);
    }

    if (! $value instanceof stdClass) {
        return $value;
    }

    $properties = get_object_vars($value);

    if (count($properties) === 1) {
        $wrapped = reset($properties);
        $part = fn (string $name) => $wrapped instanceof stdClass ? ($wrapped->{$name} ?? null) : null;

        switch (key($properties)) {
            case '$oid':
                return $wrapped === null ? new ObjectId : new ObjectId((string) $wrapped);
            case '$date':
                $date = is_string($wrapped) ? mongoDate($wrapped) : new UTCDateTime((int) ($part('$numberLong') ?? $wrapped));

                return $date ?? throw new ToolError("\"{$wrapped}\" isn't a date.");
            case '$numberLong':
                return new Int64((string) $wrapped);
            case '$numberInt':
                return (int) $wrapped;
            case '$numberDouble':
                return (float) $wrapped;
            case '$numberDecimal':
                return new Decimal128((string) $wrapped);
            case '$uuid':
                return new Binary((string) hex2bin(str_replace('-', '', (string) $wrapped)), Binary::TYPE_UUID);
            case '$binary':
                return new Binary((string) base64_decode((string) $part('base64')), (int) hexdec((string) $part('subType')));
            case '$regularExpression':
                return new Regex((string) $part('pattern'), (string) $part('options'));
            case '$timestamp':
                return new Timestamp((int) $part('i'), (int) $part('t'));
            case '$minKey':
                return new MinKey;
            case '$maxKey':
                return new MaxKey;
        }
    }

    return (object) array_map(mongoBson(...), $properties);
}

/**
 * Reads mongosh's syntax (DB-002) without running any JavaScript: values (JSON, unquoted keys, single quotes,
 * ObjectId(…), ISODate(…), new Date(…), NumberLong(…), /regex/i, …) become Extended JSON, objects as stdClass;
 * commands (db.users.find(…).limit(5)) become a list of steps.
 */
final class MongoSyntax
{
    private int $position = 0;

    private function __construct(private readonly string $text) {}

    public static function value(string $text): mixed
    {
        $parser = new self($text);
        $value = $parser->parseValue();
        $parser->end();

        return $value;
    }

    /**
     * The steps after `db`: ['prop', name] for .name or ['name'], ['call', arguments] for (…).
     *
     * @return list<array{0: 'prop'|'call', 1: mixed}>
     */
    public static function chain(string $text): array
    {
        $parser = new self($text);
        $parser->skipSpace();

        if ($parser->identifier() !== 'db') {
            throw new ToolError('Commands start with db, like db.users.find().');
        }

        $steps = [];

        while (true) {
            $parser->skipSpace();
            $next = $parser->peek();

            if ($next === '.') {
                $parser->position++;
                $parser->skipSpace();
                $steps[] = ['prop', $parser->identifier()];
            } elseif ($next === '[') {
                $parser->position++;
                $name = $parser->parseValue();
                $parser->expect(']');
                $steps[] = ['prop', is_string($name) ? $name : throw $parser->error('A collection name in [ ] must be a string.')];
            } elseif ($next === '(') {
                $parser->position++;
                $steps[] = ['call', $parser->arguments()];
            } else {
                break;
            }
        }

        $parser->end();

        return $steps;
    }

    private function peek(int $ahead = 0): string
    {
        return $this->text[$this->position + $ahead] ?? '';
    }

    private function error(string $message): ToolError
    {
        return new ToolError($message.' (at character '.($this->position + 1).')');
    }

    private function skipSpace(): void
    {
        while (preg_match('#\G(?:\s+|//[^\n]*|/\*.*?\*/)#s', $this->text, $match, 0, $this->position) && $match[0] !== '') {
            $this->position += strlen($match[0]);
        }
    }

    private function expect(string $character): void
    {
        $this->skipSpace();

        if ($this->peek() !== $character) {
            throw $this->error("Expected \"{$character}\"".($this->peek() === '' ? ', but the command ends.' : ', found "'.$this->peek().'".'));
        }

        $this->position++;
    }

    private function end(): void
    {
        $this->skipSpace();

        if ($this->peek() === ';') {
            $this->position++;
            $this->skipSpace();
        }

        if ($this->position < strlen($this->text)) {
            throw $this->error('Unexpected "'.mb_strimwidth(substr($this->text, $this->position), 0, 20, '…').'". Run one command at a time.');
        }
    }

    private function identifier(): string
    {
        if (! preg_match('/\G[A-Za-z_$][\w$]*/', $this->text, $match, 0, $this->position)) {
            throw $this->error($this->peek() === '' ? 'The command ends too soon.' : 'Expected a name, found "'.$this->peek().'".');
        }

        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function parseValue(): mixed
    {
        $this->skipSpace();
        $next = $this->peek();

        return match (true) {
            $next === '{' => $this->object(),
            $next === '[' => $this->list(),
            $next === '"', $next === "'", $next === '`' => $this->string(),
            $next === '/' => $this->regex(),
            $next === '-', $next === '+', $next === '.', ctype_digit($next) => $this->number(),
            $next !== '' && (ctype_alpha($next) || $next === '_' || $next === '$') => $this->word(),
            default => throw $this->error($next === '' ? 'The command ends too soon.' : "Unexpected \"{$next}\"."),
        };
    }

    private function object(): stdClass
    {
        $this->position++;
        $object = new stdClass;

        while (true) {
            $this->skipSpace();

            if ($this->peek() === '}') {
                $this->position++;

                return $object;
            }

            $next = $this->peek();
            $key = match (true) {
                $next === '"', $next === "'", $next === '`' => $this->string(),
                $next !== '' && ctype_digit($next) => (string) $this->number(),
                default => $this->identifier(),
            };

            if ($key === '') {
                throw $this->error('Field names can\'t be empty.');
            }

            $this->expect(':');
            $object->{$key} = $this->parseValue();
            $this->skipSpace();

            if ($this->peek() === ',') {
                $this->position++;
            } elseif ($this->peek() !== '}') {
                throw $this->error('Expected "," or "}"'.($this->peek() === '' ? ', but the command ends.' : ', found "'.$this->peek().'".'));
            }
        }
    }

    /**
     * @return list<mixed>
     */
    private function list(): array
    {
        $this->position++;
        $items = [];

        while (true) {
            $this->skipSpace();

            if ($this->peek() === ']') {
                $this->position++;

                return $items;
            }

            $items[] = $this->parseValue();
            $this->skipSpace();

            if ($this->peek() === ',') {
                $this->position++;
            } elseif ($this->peek() !== ']') {
                throw $this->error('Expected "," or "]"'.($this->peek() === '' ? ', but the command ends.' : ', found "'.$this->peek().'".'));
            }
        }
    }

    /**
     * @return list<mixed>
     */
    private function arguments(): array
    {
        $arguments = [];

        while (true) {
            $this->skipSpace();

            if ($this->peek() === ')') {
                $this->position++;

                return $arguments;
            }

            $arguments[] = $this->parseValue();
            $this->skipSpace();

            if ($this->peek() === ',') {
                $this->position++;
            } elseif ($this->peek() !== ')') {
                throw $this->error('Expected "," or ")"'.($this->peek() === '' ? ', but the command ends.' : ', found "'.$this->peek().'".'));
            }
        }
    }

    private function string(): string
    {
        $quote = $this->peek();
        $this->position++;
        $string = '';
        $escapes = ['n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\f", 'v' => "\v", '0' => "\0"];

        while (($character = $this->peek()) !== $quote) {
            if ($character === '') {
                throw $this->error('A string is missing its closing quote.');
            }

            $this->position++;

            if ($character !== '\\') {
                $string .= $character;

                continue;
            }

            $escaped = $this->peek();
            $this->position++;

            if ($escaped === 'u' && preg_match('/\G(?:\{([0-9a-fA-F]{1,6})\}|([0-9a-fA-F]{4}))/', $this->text, $match, 0, $this->position)) {
                $this->position += strlen($match[0]);
                $code = hexdec($match[1] !== '' ? $match[1] : $match[2]);

                // A surrogate pair (😀) is one character.
                if ($code >= 0xD800 && $code <= 0xDBFF && preg_match('/\G\\\\u([dD][c-fC-F][0-9a-fA-F]{2})/', $this->text, $low, 0, $this->position)) {
                    $this->position += 6;
                    $code = 0x10000 + (($code - 0xD800) << 10) + (hexdec($low[1]) - 0xDC00);
                }

                $string .= mb_chr((int) $code, 'UTF-8') ?: '';
            } elseif ($escaped === 'x' && preg_match('/\G[0-9a-fA-F]{2}/', $this->text, $match, 0, $this->position)) {
                $this->position += 2;
                $string .= mb_chr((int) hexdec($match[0]), 'UTF-8') ?: '';
            } elseif ($escaped !== "\n") {
                $string .= $escapes[$escaped] ?? $escaped;
            }
        }

        $this->position++;

        return $string;
    }

    private function regex(): stdClass
    {
        if (! preg_match('#\G/((?:\\\\.|\[(?:\\\\.|[^\]\\\\])*\]|[^/\\\\\n\[])+)/([a-z]*)#', $this->text, $match, 0, $this->position)) {
            throw $this->error('A regular expression is missing its closing /.');
        }

        $this->position += strlen($match[0]);

        return self::regularExpression($match[1], $match[2]);
    }

    private static function regularExpression(string $pattern, string $flags): stdClass
    {
        $flags = str_split($flags);
        sort($flags);

        return (object) ['$regularExpression' => (object) ['pattern' => $pattern, 'options' => implode('', $flags)]];
    }

    private function number(): int|float|stdClass
    {
        if (preg_match('/\G([+-]?)Infinity\b/', $this->text, $match, 0, $this->position)) {
            $this->position += strlen($match[0]);

            return (object) ['$numberDouble' => ($match[1] === '-' ? '-' : '').'Infinity'];
        }

        if (! preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $this->text, $match, 0, $this->position)) {
            throw $this->error('Expected a number.');
        }

        $this->position += strlen($match[0]);
        $number = ltrim($match[0], '+');

        if (preg_match('/^-?\d+$/', $number)) {
            return filter_var($number, FILTER_VALIDATE_INT) !== false ? (int) $number : (object) ['$numberDouble' => $number];
        }

        return (float) $number;
    }

    private function word(): mixed
    {
        $word = $this->identifier();

        switch ($word) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
            case 'undefined':
                return null;
            case 'NaN':
            case 'Infinity':
                return (object) ['$numberDouble' => $word];
            case 'new':
                $this->skipSpace();
                $word = $this->identifier();
        }

        $this->skipSpace();

        if ($this->peek() !== '(') {
            throw $this->error("Unknown name \"{$word}\". Put text in quotes.");
        }

        $this->position++;

        return $this->construct($word, $this->arguments());
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function construct(string $name, array $arguments): mixed
    {
        $first = $arguments[0] ?? null;
        $text = is_scalar($first) ? (string) $first : null;

        return match ($name) {
            'ObjectId', 'ObjectID' => (object) ['$oid' => $text],
            'ISODate', 'Date' => match (true) {
                $first === null => (object) ['$date' => gmdate('Y-m-d\TH:i:s\Z')],
                is_int($first) || is_float($first) => (object) ['$date' => (object) ['$numberLong' => (string) (int) $first]],
                default => (object) ['$date' => (string) $text],
            },
            'NumberLong', 'Long' => (object) ['$numberLong' => (string) $text],
            'NumberInt', 'Int32' => (int) $text,
            'Double' => (float) $text,
            'NumberDecimal', 'Decimal128' => (object) ['$numberDecimal' => (string) $text],
            'UUID' => (object) ['$uuid' => $text ?? vsprintf('%s%s-%s-4%s-%s-%s%s%s', str_split(substr_replace(bin2hex(random_bytes(16)), dechex(8 + random_int(0, 3)), 16, 1), 4))],
            'Timestamp' => (object) ['$timestamp' => $first instanceof stdClass ? $first : (object) ['t' => (int) $first, 'i' => (int) ($arguments[1] ?? 0)]],
            'BinData' => (object) ['$binary' => (object) ['base64' => (string) ($arguments[1] ?? ''), 'subType' => sprintf('%02x', (int) $first)]],
            'MinKey' => (object) ['$minKey' => 1],
            'MaxKey' => (object) ['$maxKey' => 1],
            'RegExp' => self::regularExpression((string) $text, (string) ($arguments[1] ?? '')),
            default => throw $this->error("{$name}() isn't supported."),
        };
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

        if ($pdo instanceof Mongo) {
            respond(['ok' => true, 'data' => match ($op) {
                'tables' => mongoTables($pdo),
                'rows' => mongoRows($pdo, $request),
                'changes' => mongoChanges($pdo, $request),
                'query' => mongoQuery($pdo, $request),
                'backup' => throw new ToolError('Only SQLite databases can be downloaded.'),
            }]);
        }

        respond(['ok' => true, 'data' => match ($op) {
            'tables' => tablesWithColumns($pdo, $driver),
            'rows' => rows($pdo, $driver, $request),
            'changes' => changes($pdo, $driver, $request),
            'query' => query($pdo, $request),
            'backup' => backup($pdo, $driver, $request),
        }]);
    } catch (ToolError|PDOException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()]);
    } catch (ConnectionException|AuthenticationException $e) {
        respond(['ok' => false, 'error' => "Couldn't connect to the database: ".$e->getMessage()]);
    } catch (MongoDB\Driver\Exception\Exception $e) {
        respond(['ok' => false, 'error' => $e->getMessage()]);
    }
}
