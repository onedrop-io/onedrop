<?php

/**
 * Secrets tool behind the workspace's Tools → Secrets panel.
 *
 * Secrets are the variables in the app's /workspace/.env, which the app's stack reads
 * (Laravel, Vite, Next, dotenv). This lists their names, reveals one value on request,
 * and adds, changes or deletes one, keeping every other line of the file as it was.
 *
 * Reads one JSON request from $APP_SECRETS_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * Usage: APP_SECRETS_REQUEST='{"op":"list"}' php /opt/zap/secrets.php
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const ENV_FILE = '.env';
const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/';
const VALUE_BYTES_MAX = 20_000;
const SECRETS_PER_REQUEST_MAX = 100;

final class ToolError extends RuntimeException {}

function workspace(): string
{
    return rtrim(getenv('APP_WORKSPACE') ?: '/workspace', '/');
}

function envPath(): string
{
    return workspace().'/'.ENV_FILE;
}

function respond(array $response): never
{
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}

/**
 * @return list<string>
 */
function readLines(): array
{
    $content = @file_get_contents(envPath());

    if ($content === false || $content === '') {
        return [];
    }

    return explode("\n", rtrim(str_replace("\r\n", "\n", $content), "\n"));
}

/**
 * The variables in the env file, in order: name, value, and the lines they span
 * (a double-quoted value may run over several lines).
 *
 * @param  list<string>  $lines
 * @return list<array{name: string, value: string, start: int, end: int}>
 */
function entries(array $lines): array
{
    $entries = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s?(.*)$/', $lines[$i], $m)) {
            continue;
        }

        $start = $i;
        $raw = $m[2];
        $trimmed = ltrim($raw);

        if (str_starts_with($trimmed, '"')) {
            $body = substr($trimmed, 1);

            // Keep reading lines until the closing, unescaped quote.
            while (! preg_match('/^((?:[^"\\\\]|\\\\.)*)"/s', $body, $closed) && $i + 1 < $count) {
                $body .= "\n".$lines[++$i];
            }

            $value = stripcslashes($closed[1] ?? $body);
        } elseif (str_starts_with($trimmed, "'")) {
            $end = strpos($trimmed, "'", 1);
            $value = $end === false ? substr($trimmed, 1) : substr($trimmed, 1, $end - 1);
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));
        }

        $entries[] = ['name' => $m[1], 'value' => $value, 'start' => $start, 'end' => $i];
    }

    return $entries;
}

/**
 * NAME=value, quoted so Laravel's and Node's dotenv readers both read the value back exactly.
 */
function line(string $name, string $value): string
{
    return match (true) {
        $value === '', preg_match('/^[A-Za-z0-9_.,:\/@+=%~^-]+$/', $value) === 1 => "{$name}={$value}",
        ! str_contains($value, "'") && ! str_contains($value, "\n") => "{$name}='{$value}'",
        default => $name.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
    };
}

/**
 * @param  list<string>  $lines
 */
function writeLines(array $lines): void
{
    $path = envPath();
    $temp = $path.'.zap-'.bin2hex(random_bytes(4));

    file_put_contents($temp, $lines === [] ? '' : implode("\n", $lines)."\n");
    chmod($temp, 0600);
    rename($temp, $path);

    ignoreInGit();
}

/**
 * Keep the env file out of the app's git history.
 */
function ignoreInGit(): void
{
    $gitignore = workspace().'/.gitignore';

    if (! is_file($gitignore) && ! is_dir(workspace().'/.git')) {
        return;
    }

    $patterns = array_map('trim', @file($gitignore, FILE_IGNORE_NEW_LINES) ?: []);

    if (array_intersect($patterns, ['.env', '/.env', '.env*', '/.env*']) === []) {
        $content = (string) @file_get_contents($gitignore);
        file_put_contents($gitignore, ($content === '' || str_ends_with($content, "\n") ? '' : "\n").ENV_FILE."\n", FILE_APPEND);
    }
}

function validName(mixed $name): string
{
    if (! is_string($name) || ! preg_match(NAME_PATTERN, $name)) {
        throw new ToolError('Use letters, digits and underscores for the name, starting with a letter or underscore (like STRIPE_SECRET_KEY).');
    }

    return $name;
}

/**
 * @return array{secrets: list<array{name: string}>}
 */
function listSecrets(): array
{
    $names = array_values(array_unique(array_column(entries(readLines()), 'name')));

    return ['secrets' => array_map(fn (string $name) => ['name' => $name], $names)];
}

/**
 * @param  array<string, mixed>  $request
 * @return array{name: string, value: string}
 */
function reveal(array $request): array
{
    $name = validName($request['name'] ?? null);

    foreach (entries(readLines()) as $entry) {
        if ($entry['name'] === $name) {
            return ['name' => $name, 'value' => $entry['value']];
        }
    }

    throw new ToolError("There's no secret called {$name}. Refresh to see the current list.");
}

/**
 * Add one or more secrets in a single write. Names that already exist are refused
 * unless they're listed in "replace", so a value is never overwritten by surprise.
 *
 * @param  array<string, mixed>  $request  {secrets: list<{name, value}>, replace?: list<string>}
 * @return array{secrets: list<array{name: string}>}
 */
function setSecrets(array $request): array
{
    $secrets = $request['secrets'] ?? null;
    $replace = is_array($request['replace'] ?? null) ? $request['replace'] : [];

    if (! is_array($secrets) || $secrets === [] || count($secrets) > SECRETS_PER_REQUEST_MAX) {
        throw new ToolError('Add between 1 and '.SECRETS_PER_REQUEST_MAX.' secrets at a time.');
    }

    $values = [];

    foreach ($secrets as $secret) {
        $name = validName(is_array($secret) ? ($secret['name'] ?? null) : null);
        $value = $secret['value'] ?? null;

        if (! is_string($value) || strlen($value) > VALUE_BYTES_MAX || str_contains($value, "\0")) {
            throw new ToolError("The value of {$name} is too long or not text.");
        }

        $values[$name] = str_replace("\r\n", "\n", $value);
    }

    $lines = readLines();
    $existing = array_column(entries($lines), 'name');

    foreach (array_keys($values) as $name) {
        if (in_array($name, $existing, true) && ! in_array($name, $replace, true)) {
            throw new ToolError("There's already a secret called {$name}. Edit it instead.");
        }
    }

    foreach ($values as $name => $value) {
        $matches = array_values(array_filter(entries($lines), fn (array $entry) => $entry['name'] === $name));

        if ($matches === []) {
            $lines[] = line($name, $value);

            continue;
        }

        // Replace the first definition (the one apps read) and drop any repeats after it.
        foreach (array_reverse($matches) as $index => $entry) {
            $replacement = $index === count($matches) - 1 ? [line($name, $value)] : [];
            array_splice($lines, $entry['start'], $entry['end'] - $entry['start'] + 1, $replacement);
        }
    }

    writeLines($lines);

    return listSecrets();
}

/**
 * @param  array<string, mixed>  $request
 * @return array{secrets: list<array{name: string}>}
 */
function deleteSecret(array $request): array
{
    $name = validName($request['name'] ?? null);
    $lines = readLines();

    foreach (array_reverse(entries($lines)) as $entry) {
        if ($entry['name'] === $name) {
            array_splice($lines, $entry['start'], $entry['end'] - $entry['start'] + 1);
        }
    }

    writeLines($lines);

    return listSecrets();
}

try {
    $request = json_decode((string) getenv('APP_SECRETS_REQUEST'), true);

    if (! is_array($request)) {
        throw new ToolError('The request was not valid JSON.');
    }

    respond(['ok' => true, 'data' => match ($request['op'] ?? null) {
        'list' => listSecrets(),
        'reveal' => reveal($request),
        'set-many' => setSecrets($request),
        'delete' => deleteSecret($request),
        default => throw new ToolError('Unknown operation.'),
    }]);
} catch (ToolError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()]);
}
