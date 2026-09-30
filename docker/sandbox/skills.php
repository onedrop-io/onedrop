<?php

/**
 * Agent skills tool behind Tools → Agent Skills (SKILL-001..004).
 *
 * - "list": the project's own skills (SKILL.md folders in .agents/skills, .claude/skills and .opencode/skills).
 * - "export": one project skill's files, to save a copy in the user's skills.
 * - "sync": puts skills where the agent looks for them before a run. The user's skills that are on come from the
 *   platform as a bundle, kept in ~/.onedrop/skills/library with its hash; when the hash the platform sends differs
 *   and there's no bundle, it answers {"current": false} and the platform sends the bundle. The library, plus project
 *   skills the agent doesn't read from their folder (Claude Code only reads .claude/skills, Codex only .agents/skills),
 *   are copied into ~/.claude/skills (Claude Code) or ~/.agents/skills (OpenCode, Codex). Only one of the two is
 *   filled at a time, because OpenCode reads both. What was copied is tracked in ~/.onedrop/skills/installed.json, so
 *   skills put there by hand are never touched.
 *
 * Reads one JSON request from $APP_SKILLS_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * Usage: APP_SKILLS_REQUEST='{"op":"list"}' php /opt/onedrop/skills.php
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const PROJECT_ROOTS = ['.agents/skills', '.claude/skills', '.opencode/skills', '.opencode/skill'];
const SKILLS_MAX = 200;
const HEAD_BYTES = 8192;
const EXPORT_FILES_MAX = 50;
const EXPORT_BYTES_MAX = 1024 * 1024;
const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

final class ToolError extends RuntimeException {}

function workspace(): string
{
    return rtrim(getenv('APP_WORKSPACE') ?: '/workspace', '/');
}

/** The sandbox user's real home: some providers run commands with HOME=/workspace. */
function home(): string
{
    if ($home = getenv('APP_SKILLS_HOME')) {
        return rtrim($home, '/');
    }

    if (function_exists('posix_getpwuid') && ($user = posix_getpwuid(posix_geteuid())) && ($user['dir'] ?? '') !== '') {
        return rtrim($user['dir'], '/');
    }

    return rtrim((string) getenv('HOME'), '/');
}

function store(): string
{
    return home().'/.onedrop/skills';
}

function respond(array $response): never
{
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}

/**
 * The project's skill folders by name; the first root with a name wins.
 *
 * @param  list<string>  $roots
 * @return array<string, array{root: string, dir: string}>
 */
function projectSkills(array $roots = PROJECT_ROOTS): array
{
    $skills = [];

    foreach ($roots as $root) {
        $base = workspace().'/'.$root;

        if (! is_dir($base) || is_link($base)) {
            continue;
        }

        $names = scandir($base) ?: [];
        sort($names);

        foreach ($names as $name) {
            $dir = "{$base}/{$name}";

            if (isset($skills[$name]) || ! preg_match(NAME_PATTERN, $name) || ! is_dir($dir) || ! is_file("{$dir}/SKILL.md")) {
                continue;
            }

            $skills[$name] = ['root' => $root, 'dir' => $dir];

            if (count($skills) >= SKILLS_MAX) {
                return $skills;
            }
        }
    }

    return $skills;
}

function listSkills(): array
{
    $list = [];

    foreach (projectSkills() as $name => $skill) {
        $list[] = [
            'folder' => $name,
            'path' => "{$skill['root']}/{$name}",
            'head' => (string) file_get_contents("{$skill['dir']}/SKILL.md", false, null, 0, HEAD_BYTES),
        ];
    }

    return ['skills' => $list];
}

/**
 * Every regular file under $dir, by path relative to it (symlinks skipped, so nothing outside comes along).
 *
 * @return array<string, string> path => absolute path
 */
function filesIn(string $dir, string $prefix = ''): array
{
    $files = [];
    $names = scandir($dir) ?: [];
    sort($names);

    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = "{$dir}/{$name}";

        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            $files += filesIn($path, "{$prefix}{$name}/");
        } elseif (is_file($path)) {
            $files["{$prefix}{$name}"] = $path;
        }
    }

    return $files;
}

function export(array $request): array
{
    $path = (string) ($request['path'] ?? '');
    $match = null;

    foreach (projectSkills() as $name => $skill) {
        if ("{$skill['root']}/{$name}" === $path) {
            $match = $skill['dir'];
        }
    }

    if ($match === null) {
        throw new ToolError("That skill isn't in the project anymore.");
    }

    $files = filesIn($match);
    $bytes = 0;

    if (count($files) > EXPORT_FILES_MAX) {
        throw new ToolError('The skill is too big: skills can have up to '.EXPORT_FILES_MAX.' files and 1 MB.');
    }

    $out = [];

    foreach ($files as $relative => $absolute) {
        $bytes += (int) filesize($absolute);

        if ($bytes > EXPORT_BYTES_MAX) {
            throw new ToolError('The skill is too big: skills can have up to '.EXPORT_FILES_MAX.' files and 1 MB.');
        }

        $out[] = ['path' => $relative, 'data' => base64_encode((string) file_get_contents($absolute))];
    }

    return ['files' => $out];
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            removeTree("{$path}/{$name}");
        }
    }

    @rmdir($path);
}

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0755, true);

    foreach (filesIn($from) as $relative => $absolute) {
        @mkdir(dirname("{$to}/{$relative}"), 0755, true);
        copy($absolute, "{$to}/{$relative}");
    }
}

function treeHash(string $dir): string
{
    $hash = hash_init('sha1');

    foreach (filesIn($dir) as $relative => $absolute) {
        hash_update($hash, $relative."\0".hash_file('sha1', $absolute)."\0");
    }

    return hash_final($hash);
}

/**
 * Replace the library with the platform's bundle: {"skills": [{"name", "content", "files": [{"path", "data"}]}]}.
 */
function unpackBundle(string $bundlePath, string $hash): void
{
    $bundle = json_decode(base64_decode((string) file_get_contents($bundlePath), true) ?: '', true);
    @unlink($bundlePath);

    if (! is_array($bundle) || ! is_array($bundle['skills'] ?? null)) {
        throw new ToolError("The skills didn't arrive in one piece.");
    }

    $next = store().'/library.next';
    removeTree($next);
    mkdir($next, 0755, true);

    foreach ($bundle['skills'] as $skill) {
        $name = (string) ($skill['name'] ?? '');

        if (! preg_match(NAME_PATTERN, $name) || is_dir("{$next}/{$name}")) {
            continue;
        }

        mkdir("{$next}/{$name}", 0755, true);
        file_put_contents("{$next}/{$name}/SKILL.md", (string) ($skill['content'] ?? ''));

        foreach (is_array($skill['files'] ?? null) ? $skill['files'] : [] as $file) {
            $path = (string) ($file['path'] ?? '');

            if ($path === '' || $path === 'SKILL.md' || str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
                continue;
            }

            @mkdir(dirname("{$next}/{$name}/{$path}"), 0755, true);
            file_put_contents("{$next}/{$name}/{$path}", base64_decode((string) ($file['data'] ?? ''), true) ?: '');
        }
    }

    removeTree(store().'/library');
    rename($next, store().'/library');
    file_put_contents(store().'/hash', $hash);
}

function sync(array $request): array
{
    $agent = (string) ($request['agent'] ?? 'opencode');
    $hash = (string) ($request['hash'] ?? '');
    @mkdir(store(), 0755, true);

    if (is_string($request['bundle'] ?? null)) {
        unpackBundle($request['bundle'], $hash);
    } elseif (trim((string) @file_get_contents(store().'/hash')) !== $hash) {
        return ['current' => false];
    }

    $claude = $agent === 'claude_code';
    $target = home().($claude ? '/.claude/skills' : '/.agents/skills');

    // Project skills the agent reads from the repo itself, then ones it doesn't (copied), then the library.
    $native = match ($agent) {
        'claude_code' => projectSkills(['.claude/skills']),
        'codex' => projectSkills(['.agents/skills']),
        default => projectSkills(),
    };
    $wanted = [];

    foreach (projectSkills() as $name => $skill) {
        if (! isset($native[$name])) {
            $wanted[$name] = $skill['dir'];
        }
    }

    $library = store().'/library';

    foreach (is_dir($library) ? (scandir($library) ?: []) : [] as $name) {
        if (preg_match(NAME_PATTERN, $name) && is_dir("{$library}/{$name}") && ! isset($native[$name]) && ! isset($wanted[$name])) {
            $wanted[$name] = "{$library}/{$name}";
        }
    }

    $manifestPath = store().'/installed.json';
    $installed = json_decode((string) @file_get_contents($manifestPath), true);
    $installed = is_array($installed) ? $installed : [];

    foreach ($installed as $path => $treeHash) {
        if (dirname($path) !== $target || ! isset($wanted[basename($path)])) {
            removeTree($path);
            unset($installed[$path]);
        }
    }

    @mkdir($target, 0755, true);
    $skills = [];

    foreach ($wanted as $name => $source) {
        $path = "{$target}/{$name}";

        // Someone put a skill with this name there by hand: theirs wins.
        if (file_exists($path) && ! isset($installed[$path])) {
            continue;
        }

        $sourceHash = treeHash($source);

        if (($installed[$path] ?? null) !== $sourceHash) {
            $temp = "{$target}/.{$name}.onedrop-tmp";
            removeTree($temp);
            copyTree($source, $temp);
            removeTree($path);
            rename($temp, $path);
            $installed[$path] = $sourceHash;
        }

        $skills[] = $name;
    }

    file_put_contents($manifestPath, json_encode($installed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    return ['current' => true, 'installed' => $skills];
}

try {
    $request = json_decode((string) getenv('APP_SKILLS_REQUEST'), true);

    if (! is_array($request)) {
        throw new ToolError('Bad request.');
    }

    $data = match ($request['op'] ?? null) {
        'list' => listSkills(),
        'export' => export($request),
        'sync' => sync($request),
        default => throw new ToolError('Unknown operation.'),
    };

    respond(['ok' => true, 'data' => $data]);
} catch (ToolError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'The skills tool failed: '.$e->getMessage()]);
}
