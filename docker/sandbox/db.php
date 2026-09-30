<?php

/**
 * Database tool behind the workspace's Tools → Database panel.
 *
 * Finds the app's databases (from .env files and SQLite files in the workspace),
 * then browses, edits or queries one of them. Connection details and passwords
 * never leave the sandbox: the platform only ever sees connection ids.
 *
 * Reads one JSON request from $APP_DB_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
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
    ];
}

/**
 * Connections declared by a dotenv file: a *_URL variable or Laravel's DB_* settings.
 *
 * @return list<array<string, mixed>>
 */
function envConnections(string $file): array
{
    $env = readDotenv($file);
    $dir = dirname($file);
    $source = relative($file);
    $found = [];

    foreach (['DATABASE_URL', 'DB_URL', 'POSTGRES_URL', 'MYSQL_URL'] as $key) {
        if (! empty($env[$key]) && ($connection = fromUrl($env[$key], $dir))) {
            $found[] = $connection + ['id' => "env:{$source}:{$key}", 'source' => "{$source} ({$key})"];
        }
    }

    $driver = $env['DB_CONNECTION'] ?? null;

    if ($found === [] && $driver === 'sqlite') {
        $database = $env['DB_DATABASE'] ?? '';
        $path = $database === '' || $database === ':memory:' ? 'database/database.sqlite' : $database;
        $found[] = sqliteConnection(resolvePath($path, $dir)) + ['source' => "{$source} (DB_CONNECTION)"];
    } elseif ($found === [] && in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
        $found[] = serverConnection(
            $driver === 'pgsql' ? 'pgsql' : 'mysql',
            $env['DB_HOST'] ?? '127.0.0.1',
            isset($env['DB_PORT']) ? (int) $env['DB_PORT'] : null,
            $env['DB_DATABASE'] ?? '',
            $env['DB_USERNAME'] ?? null,
            $env['DB_PASSWORD'] ?? null,
        ) + ['id' => "env:{$source}:DB_CONNECTION", 'source' => "{$source} (DB_CONNECTION)"];
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

        if ($name === '.' || $name === '..' || is_link($path) || in_array(relative($path), SKIPPED_DIRS, true) || in_array($name, SKIPPED_DIRS, true)) {
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

    foreach ($envFiles as $file) {
        foreach (envConnections($file) as $connection) {
            $byId[$connection['id']] ??= $connection;
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

// auth.php includes this file for its helpers; only run a request when called directly.
if (! defined('APP_DB_LIBRARY')) {
    try {
        $request = json_decode((string) getenv('APP_DB_REQUEST'), true);

        if (! is_array($request)) {
            throw new ToolError('The request was not valid JSON.');
        }

        $op = $request['op'] ?? null;

        if ($op === 'connections') {
            respond(['ok' => true, 'data' => array_map(describe(...), connections())]);
        }

        if (! in_array($op, ['tables', 'rows', 'changes', 'query'], true)) {
            throw new ToolError('Unknown operation.');
        }

        [$pdo, $driver] = open((string) ($request['connection'] ?? ''));

        respond(['ok' => true, 'data' => match ($op) {
            'tables' => tablesWithColumns($pdo, $driver),
            'rows' => rows($pdo, $driver, $request),
            'changes' => changes($pdo, $driver, $request),
            'query' => query($pdo, $request),
        }]);
    } catch (ToolError|PDOException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()]);
    }
}
