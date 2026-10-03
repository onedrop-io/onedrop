<?php

/**
 * Moves a hosted app's SQLite data into its new Postgres (HOST-009, Move to Postgres), once, on the hosted machine.
 *
 * The app's own migrations have already made the tables in Postgres (.onedrop/migrate); this copies every row of
 * every SQLite table into the Postgres table of the same name, in one transaction: all of it or nothing. Values are
 * turned into what each Postgres column holds (SQLite's 0/1 into booleans, Unix times into timestamps, blobs into
 * bytea), tables go in foreign-key order, and each id sequence carries on after the copied rows. Laravel's
 * `migrations` table is left as the migrations made it.
 *
 * Usage: DATABASE_URL=postgresql://… php pg-import.php /workspace/database/database.sqlite
 * Prints {"tables": n, "rows": n} when done, or an error on stderr with exit code 1.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const BATCH_ROWS = 200;

/** Tables the migrations own: copying the SQLite ones would say migrations ran twice. */
const SKIPPED = ['migrations'];

final class ImportError extends RuntimeException {}

/**
 * A PDO connection to the Postgres in a postgres:// URL.
 */
function postgres(string $url): PDO
{
    $parts = parse_url($url);

    if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
        throw new ImportError('DATABASE_URL is not a Postgres URL.');
    }

    parse_str($parts['query'] ?? '', $query);
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $parts['host'] ?? '127.0.0.1',
        $parts['port'] ?? 5432,
        ltrim(rawurldecode($parts['path'] ?? ''), '/') ?: 'postgres',
        is_string($query['sslmode'] ?? null) ? $query['sslmode'] : 'prefer',
    );

    return new PDO($dsn, isset($parts['user']) ? rawurldecode($parts['user']) : null, isset($parts['pass']) ? rawurldecode($parts['pass']) : null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * @return list<string>
 */
function sqliteTables(PDO $sqlite): array
{
    return $sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * @return list<string>
 */
function sqliteColumns(PDO $sqlite, string $table): array
{
    return array_column($sqlite->query('PRAGMA table_info('.$sqlite->quote($table).')')->fetchAll(), 'name');
}

/**
 * Each Postgres column's type and whether it takes its value from a sequence.
 *
 * @return array<string, array{type: string, sequence: bool}>
 */
function postgresColumns(PDO $pg, string $table): array
{
    $statement = $pg->prepare(
        'SELECT column_name, data_type, column_default, is_identity FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
    );
    $statement->execute([$table]);
    $columns = [];

    foreach ($statement->fetchAll() as $column) {
        $columns[$column['column_name']] = [
            'type' => $column['data_type'],
            'sequence' => $column['is_identity'] === 'YES' || str_starts_with((string) $column['column_default'], 'nextval('),
        ];
    }

    return $columns;
}

/**
 * The tables in an order where each comes after the tables its foreign keys point at.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function inForeignKeyOrder(PDO $pg, array $tables): array
{
    $references = array_fill_keys($tables, []);

    foreach ($pg->query(
        "SELECT tc.table_name, ccu.table_name AS referenced FROM information_schema.table_constraints tc
         JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
         WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()",
    )->fetchAll() as $reference) {
        if (isset($references[$reference['table_name']]) && $reference['referenced'] !== $reference['table_name'] && isset($references[$reference['referenced']])) {
            $references[$reference['table_name']][] = $reference['referenced'];
        }
    }

    $ordered = [];
    $visiting = [];
    $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $references): void {
        if (in_array($table, $ordered, true) || isset($visiting[$table])) {
            return;
        }

        $visiting[$table] = true;

        foreach ($references[$table] as $referenced) {
            $visit($referenced);
        }

        $ordered[] = $table;
    };

    foreach ($tables as $table) {
        $visit($table);
    }

    return $ordered;
}

/**
 * A SQLite value as the Postgres column wants it.
 */
function convert(mixed $value, string $type): mixed
{
    if ($value === null) {
        return null;
    }

    return match (true) {
        $type === 'boolean' => in_array(strtolower((string) $value), ['1', 'true', 't', 'yes', 'y', 'on'], true) ? 'true' : 'false',
        // Unix times (seconds, or milliseconds as JavaScript writes them, both UTC) where Postgres keeps a date.
        (str_starts_with($type, 'timestamp') || $type === 'date') && is_numeric($value) => gmdate(
            $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s',
            (int) ((float) $value > 99_999_999_999 ? (float) $value / 1000 : $value),
        ),
        default => $value,
    };
}

function quote(string $identifier): string
{
    return '"'.str_replace('"', '""', $identifier).'"';
}

/**
 * @return array{tables: int, rows: int}
 */
function import(PDO $sqlite, PDO $pg): array
{
    $tables = array_values(array_diff(sqliteTables($sqlite), SKIPPED));
    $columns = [];
    $problems = [];

    foreach ($tables as $table) {
        $target = postgresColumns($pg, $table);

        if ($target === []) {
            $problems[] = "table {$table} isn't in Postgres";

            continue;
        }

        $missing = array_diff(sqliteColumns($sqlite, $table), array_keys($target));

        if ($missing !== []) {
            $problems[] = "{$table} has no ".implode(', ', $missing).' in Postgres';

            continue;
        }

        $columns[$table] = array_intersect_key($target, array_flip(sqliteColumns($sqlite, $table)));
    }

    // Copying only part of the data would lose the rest without saying: refuse instead.
    if ($problems !== []) {
        throw new ImportError("The app's Postgres tables don't match its SQLite ones (".implode('; ', $problems).'). Run its migrations against Postgres in the sandbox and fix them, then publish again.');
    }

    $ordered = inForeignKeyOrder($pg, array_keys($columns));
    $total = 0;

    $pg->beginTransaction();

    try {
        if ($ordered !== []) {
            $pg->exec('TRUNCATE '.implode(', ', array_map(quote(...), $ordered)).' RESTART IDENTITY CASCADE');
        }

        foreach ($ordered as $table) {
            $names = array_keys($columns[$table]);
            $rows = $sqlite->query('SELECT '.implode(', ', array_map(quote(...), $names)).' FROM '.quote($table).' ORDER BY rowid');
            $batch = [];

            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                $batch[] = $row;

                if (count($batch) === BATCH_ROWS) {
                    $total += insert($pg, $table, $columns[$table], $batch);
                    $batch = [];
                }
            }

            $total += insert($pg, $table, $columns[$table], $batch);

            foreach ($columns[$table] as $name => $column) {
                if ($column['sequence']) {
                    $pg->query(sprintf(
                        'SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE((SELECT MAX(%s) FROM %s), 0) + 1, false)',
                        $pg->quote(quote($table)),
                        $pg->quote($name),
                        quote($name),
                        quote($table),
                    ));
                }
            }
        }

        $pg->commit();
    } catch (Throwable $e) {
        $pg->rollBack();

        throw $e;
    }

    return ['tables' => count($ordered), 'rows' => $total];
}

/**
 * @param  array<string, array{type: string, sequence: bool}>  $columns
 * @param  list<list<mixed>>  $rows
 */
function insert(PDO $pg, string $table, array $columns, array $rows): int
{
    if ($rows === []) {
        return 0;
    }

    $names = array_keys($columns);
    $placeholders = '('.implode(', ', array_fill(0, count($names), '?')).')';
    $statement = $pg->prepare(sprintf(
        'INSERT INTO %s (%s) VALUES %s',
        quote($table),
        implode(', ', array_map(quote(...), $names)),
        implode(', ', array_fill(0, count($rows), $placeholders)),
    ));
    $position = 1;

    foreach ($rows as $row) {
        foreach ($row as $index => $value) {
            $type = $columns[$names[$index]]['type'];
            $value = convert($value, $type);
            $statement->bindValue($position++, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                $type === 'bytea' => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            });
        }
    }

    $statement->execute();

    return count($rows);
}

try {
    $path = $argv[1] ?? '';

    if (! is_file($path)) {
        throw new ImportError("There's no SQLite database at {$path}.");
    }

    $sqlite = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    echo json_encode(import($sqlite, postgres((string) getenv('DATABASE_URL')))), "\n";
} catch (ImportError|PDOException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
