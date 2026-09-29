<?php

/**
 * Git tool behind the workspace's Tools → Git panel: the app's branch, uncommitted changes and history (and each
 * commit's files and diffs),
 * committing, discarding, switching branches, and restoring an earlier version (as a new commit).
 *
 * Remotes are handled by the platform, never here: pushes and pulls travel as git bundles, so the remote's
 * credentials stay out of the sandbox. This only records what the platform pushed or fetched.
 *
 * Reads one JSON request from $APP_GIT_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * Usage: APP_GIT_REQUEST='{"op":"status"}' php /opt/zap/git.php
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const CHANGES_MAX = 500;
const LOG_MAX = 200;
const FILES_MAX = 300;
const DIFF_BYTES_MAX = 200_000;
const AGENT_NAME = 'OneDrop';
const BRANCH_PATTERN = '/^(?!-)(?!.*\.\.)(?!.*\/\/)(?!.*@\{)[A-Za-z0-9._\/-]{1,100}(?<!\.lock)(?<![\/.])$/';

final class ToolError extends RuntimeException {}

function workspace(): string
{
    return rtrim(getenv('APP_WORKSPACE') ?: '/workspace', '/');
}

function respond(array $response): never
{
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}

/**
 * Run git in the workspace. Returns [exit code, stdout, stderr].
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return array{int, string, string}
 */
function git(array $args, array $env = [], ?string $input = null): array
{
    $process = proc_open(
        ['git', ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        workspace(),
        [...getenv(), 'GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => '*', 'GIT_TERMINAL_PROMPT' => '0', ...$env],
    );

    if (! is_resource($process)) {
        throw new ToolError('Could not run git.');
    }

    fwrite($pipes[0], $input ?? '');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output, (string) $error];
}

/**
 * Run git and return its output, or fail with its error.
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 */
function gitOrFail(array $args, array $env = [], ?string $input = null): string
{
    [$code, $output, $error] = git($args, $env, $input);

    if ($code !== 0) {
        throw new ToolError(trim($error) !== '' ? trim(preg_replace('/^(fatal|error): /m', '', $error)) : 'Git failed.');
    }

    return $output;
}

function isRepository(): bool
{
    return is_dir(workspace().'/.git');
}

function head(): ?string
{
    [$code, $output] = git(['rev-parse', '--verify', '-q', 'HEAD']);

    return $code === 0 ? trim($output) : null;
}

function currentBranch(): ?string
{
    [$code, $output] = git(['symbolic-ref', '-q', '--short', 'HEAD']);

    return $code === 0 ? trim($output) : null;
}

function validBranch(mixed $branch): string
{
    if (! is_string($branch) || ! preg_match(BRANCH_PATTERN, $branch)) {
        throw new ToolError('That isn\'t a valid branch name.');
    }

    return $branch;
}

function validCommit(mixed $sha): string
{
    if (! is_string($sha) || ! preg_match('/^[0-9a-f]{7,40}$/', $sha)) {
        throw new ToolError('That isn\'t a valid commit.');
    }

    [$code] = git(['cat-file', '-e', "{$sha}^{commit}"]);

    if ($code !== 0) {
        throw new ToolError('That version isn\'t in this project\'s history.');
    }

    return $sha;
}

/**
 * The author of a commit made from the panel: the signed-in user.
 *
 * @return array<string, string>
 */
function authorEnv(array $request): array
{
    $name = is_string($request['name'] ?? null) && trim($request['name']) !== '' ? trim($request['name']) : AGENT_NAME;
    $email = is_string($request['email'] ?? null) && trim($request['email']) !== '' ? trim($request['email']) : 'agent@onedrop.io';

    return ['ZAP_GIT_AUTHOR_NAME' => $name, 'ZAP_GIT_AUTHOR_EMAIL' => $email];
}

/**
 * Commit everything through /opt/zap/checkpoint (so dependencies and secrets stay out). Returns whether it committed.
 */
function checkpoint(string $message, array $author): bool
{
    $before = head();
    $script = getenv('APP_CHECKPOINT') ?: '/opt/zap/checkpoint';
    $process = proc_open([$script], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, workspace(), [...getenv(), 'ZAP_WORKSPACE' => workspace(), ...$author]);

    if (! is_resource($process)) {
        throw new ToolError('Could not commit.');
    }

    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    proc_close($process);

    return head() !== $before;
}

/**
 * Uncommitted changes: path and a one-letter state (M modified, A added, D deleted, R renamed, U conflicted, ? new).
 *
 * @return array{list<array{path: string, status: string}>, bool}
 */
function changes(): array
{
    $output = gitOrFail(['status', '--porcelain=v1', '-z']);
    $entries = $output === '' ? [] : explode("\0", rtrim($output, "\0"));
    $changes = [];

    for ($i = 0; $i < count($entries); $i++) {
        $entry = $entries[$i];
        $code = substr($entry, 0, 2);
        $path = substr($entry, 3);

        // Renames and copies are followed by their original path.
        if ($code[0] === 'R' || $code[0] === 'C') {
            $i++;
        }

        $status = match (true) {
            $code === '??' => '?',
            str_contains($code, 'U') || $code === 'AA' || $code === 'DD' => 'U',
            str_contains($code, 'R') => 'R',
            str_contains($code, 'D') => 'D',
            str_contains($code, 'A') => 'A',
            default => 'M',
        };

        $changes[] = ['path' => $path, 'status' => $status];
    }

    return [array_slice($changes, 0, CHANGES_MAX), count($changes) > CHANGES_MAX];
}

/**
 * How far the branch is from what the platform last pushed or fetched (refs/remotes/origin/<branch>).
 *
 * @return array{ahead: int, behind: int}|null
 */
function tracking(?string $branch): ?array
{
    if ($branch === null || head() === null) {
        return null;
    }

    [$code, $output] = git(['rev-list', '--left-right', '--count', "refs/remotes/origin/{$branch}...HEAD"]);

    if ($code !== 0) {
        return null;
    }

    [$behind, $ahead] = array_map('intval', preg_split('/\s+/', trim($output)));

    return ['ahead' => $ahead, 'behind' => $behind];
}

function status(): array
{
    if (! isRepository()) {
        return ['initialized' => false, 'branch' => null, 'branches' => [], 'head' => null, 'changes' => [], 'more_changes' => false, 'tracking' => null, 'state' => null];
    }

    $branch = currentBranch();
    [$changes, $more] = changes();
    $branches = array_values(array_filter(explode("\n", trim(gitOrFail(['for-each-ref', '--format=%(refname:short)', 'refs/heads'])))));

    $state = match (true) {
        file_exists(workspace().'/.git/MERGE_HEAD') => 'merging',
        is_dir(workspace().'/.git/rebase-merge') || is_dir(workspace().'/.git/rebase-apply') => 'rebasing',
        default => null,
    };

    return [
        'initialized' => true,
        // A repository before its first commit is still on its branch.
        'branch' => $branch,
        'branches' => $branches,
        'head' => head(),
        'changes' => $changes,
        'more_changes' => $more,
        'tracking' => tracking($branch),
        'state' => $state,
    ];
}

/**
 * Commits, newest first, a page at a time ("offset", "limit"). With "query", only commits whose message or author
 * contains it (ignoring case; "agent" also finds the agent's), or whose id starts with it, across the whole history.
 */
function history(array $request): array
{
    if (! isRepository() || head() === null) {
        return ['commits' => [], 'more' => false];
    }

    $limit = max(1, min(LOG_MAX, (int) ($request['limit'] ?? 50)));
    $offset = max(0, min(100_000, (int) ($request['offset'] ?? 0)));
    $query = is_string($request['query'] ?? null) ? trim($request['query']) : '';

    if ($query === '') {
        $commits = logCommits(['--skip='.$offset, '--max-count='.($limit + 1)]);
    } else {
        $window = '--max-count='.($offset + $limit + 1);
        $matches = [
            ...logCommits([$window, '--regexp-ignore-case', '--fixed-strings', '--grep='.$query]),
            ...logCommits([$window, '--regexp-ignore-case', '--fixed-strings', '--author='.$query]),
        ];

        if (str_contains('agent', strtolower($query))) {
            array_push($matches, ...logCommits([$window, '--fixed-strings', '--author='.AGENT_NAME]));
        }

        if (preg_match('/^[0-9a-f]{4,40}$/i', $query)) {
            [$code, $output] = git(['rev-parse', '--verify', '-q', strtolower($query).'^{commit}']);

            if ($code === 0) {
                array_push($matches, ...logCommits(['-1', trim($output)]));
            }
        }

        $unique = [];

        foreach ($matches as $commit) {
            $unique[$commit['sha']] = $commit;
        }

        usort($unique, fn (array $a, array $b) => strtotime($b['date']) <=> strtotime($a['date']));
        $commits = array_slice($unique, $offset, $limit + 1);
    }

    return ['commits' => array_slice($commits, 0, $limit), 'more' => count($commits) > $limit];
}

/**
 * @param  list<string>  $options
 * @return list<array{sha: string, subject: string, author: string, email: string, date: string, agent: bool}>
 */
function logCommits(array $options): array
{
    $output = gitOrFail(['log', ...$options, '--format=%H%x1f%s%x1f%an%x1f%ae%x1f%aI%x1e']);
    $commits = [];

    foreach (array_filter(explode("\x1e", $output), fn (string $record) => trim($record) !== '') as $record) {
        [$sha, $subject, $author, $email, $date] = explode("\x1f", trim($record, "\n"));
        $commits[] = ['sha' => $sha, 'subject' => $subject, 'author' => $author, 'email' => $email, 'date' => $date, 'agent' => $author === AGENT_NAME];
    }

    return $commits;
}

function validPath(mixed $path): string
{
    if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
        throw new ToolError('That isn\'t a file in this project.');
    }

    return $path;
}

/**
 * One commit in full: message, author, date, parents, and the files it changed with lines added and removed.
 */
function showCommit(array $request): array
{
    $sha = validCommit($request['sha'] ?? null);
    $meta = gitOrFail(['show', '-s', '--format=%H%x1f%an%x1f%ae%x1f%aI%x1f%P%x1f%s%x1f%b', $sha]);
    [$full, $author, $email, $date, $parents, $subject, $body] = explode("\x1f", rtrim($meta, "\n"), 7);

    $statuses = [];
    $entries = explode("\0", rtrim(gitOrFail(['diff-tree', '-r', '--root', '--no-commit-id', '--no-renames', '--name-status', '-z', $sha]), "\0"));

    for ($i = 0; $i + 1 < count($entries); $i += 2) {
        $statuses[$entries[$i + 1]] = $entries[$i];
    }

    $files = [];

    foreach (array_filter(explode("\0", gitOrFail(['diff-tree', '-r', '--root', '--no-commit-id', '--no-renames', '--numstat', '-z', $sha]))) as $line) {
        [$added, $removed, $path] = explode("\t", $line, 3);
        $binary = $added === '-';
        $files[] = [
            'path' => $path,
            'status' => $statuses[$path] ?? 'M',
            'additions' => $binary ? null : (int) $added,
            'deletions' => $binary ? null : (int) $removed,
            'binary' => $binary,
        ];
    }

    return [
        'sha' => $full,
        'subject' => $subject,
        'body' => trim($body),
        'author' => $author,
        'email' => $email,
        'date' => $date,
        'agent' => $author === AGENT_NAME,
        'parents' => array_values(array_filter(explode(' ', $parents))),
        'files' => array_slice($files, 0, FILES_MAX),
        'more_files' => count($files) > FILES_MAX,
    ];
}

/**
 * The patch one commit made to one file, cut short when it's very large.
 */
function diff(array $request): array
{
    $sha = validCommit($request['sha'] ?? null);
    $path = validPath($request['path'] ?? null);
    $patch = gitOrFail(['show', '--format=', '--no-renames', '--no-color', '--patch', $sha, '--', $path]);

    return [
        'path' => $path,
        'patch' => substr($patch, 0, DIFF_BYTES_MAX),
        'truncated' => strlen($patch) > DIFF_BYTES_MAX,
    ];
}

function commit(array $request): array
{
    $message = is_string($request['message'] ?? null) ? trim($request['message']) : '';

    if ($message === '') {
        throw new ToolError('Write a message for the commit.');
    }

    if (strlen($message) > 5000) {
        throw new ToolError('That message is too long.');
    }

    if (! checkpoint($message, authorEnv($request))) {
        throw new ToolError('There\'s nothing to commit.');
    }

    return status();
}

function discard(array $request): array
{
    if (! isRepository()) {
        throw new ToolError('There\'s nothing to discard.');
    }

    $path = $request['path'] ?? null;
    $hasHead = head() !== null;

    if ($path === null) {
        if ($hasHead) {
            gitOrFail(['reset', '--hard', '-q']);
        } else {
            gitOrFail(['rm', '-r', '-q', '--cached', '--ignore-unmatch', '.']);
        }

        // Untracked files go too; ignored ones (node_modules, .env) stay.
        gitOrFail(['clean', '-f', '-d', '-q']);

        return status();
    }

    validPath($path);

    [$tracked] = git(['ls-files', '--error-unmatch', '--', $path]);
    [$inHead] = $hasHead ? git(['cat-file', '-e', "HEAD:{$path}"]) : [1];

    if ($inHead === 0) {
        gitOrFail(['restore', '--source=HEAD', '--staged', '--worktree', '--', $path]);
    } elseif ($tracked === 0) {
        // Added since the last commit: unstage it and remove it.
        gitOrFail(['rm', '-r', '-q', '--cached', '--', $path]);
        gitOrFail(['clean', '-f', '-d', '-q', '--', $path]);
    } else {
        gitOrFail(['clean', '-f', '-d', '-q', '--', $path]);
    }

    return status();
}

function switchBranch(array $request): array
{
    $branch = validBranch($request['branch'] ?? null);

    if (! isRepository() || head() === null) {
        throw new ToolError('Branches work once the project has its first commit.');
    }

    gitOrFail(($request['create'] ?? false) === true ? ['switch', '-q', '-c', $branch] : ['switch', '-q', $branch]);

    return status();
}

/**
 * Make the workspace match an earlier commit, as a new commit on top (nothing is lost): uncommitted changes
 * are committed first, then every tracked file is put back as it was.
 */
function restore(array $request): array
{
    if (! isRepository() || head() === null) {
        throw new ToolError('There\'s no history to restore from yet.');
    }

    $sha = validCommit($request['sha'] ?? null);
    $subject = trim(gitOrFail(['log', '-1', '--format=%s', $sha]));
    $short = substr($sha, 0, 7);
    $author = authorEnv($request);

    checkpoint("Changes before restoring {$short}", $author);
    gitOrFail(['restore', "--source={$sha}", '--staged', '--worktree', '--', ':/']);
    checkpoint("Restore \"{$subject}\" ({$short})", $author);

    return status();
}

/**
 * Remember what the platform pushed, so the panel can say how far ahead the branch is.
 */
function pushed(array $request): array
{
    $branch = validBranch($request['branch'] ?? null);
    $sha = validCommit($request['sha'] ?? null);
    gitOrFail(['update-ref', "refs/remotes/origin/{$branch}", $sha]);

    return status();
}

/**
 * Bring in a branch the platform fetched from the remote (a bundle it copied in), fast-forward only. A project with
 * no commits yet (or no repository) takes the remote's branch as its own.
 */
function pulled(array $request): array
{
    $branch = validBranch($request['branch'] ?? null);
    $bundle = $request['bundle'] ?? null;

    if (! is_string($bundle) || ! str_starts_with($bundle, '/tmp/') || ! is_file($bundle)) {
        throw new ToolError('The fetched changes are missing.');
    }

    if (! isRepository()) {
        gitOrFail(['init', '-q', '-b', $branch]);
    }

    try {
        gitOrFail(['fetch', '-q', $bundle, "+refs/heads/{$branch}:refs/remotes/origin/{$branch}"]);
    } finally {
        @unlink($bundle);
    }

    // No commits yet: the remote's branch becomes the project's (an import).
    if (head() === null) {
        [$code, , $error] = git(['checkout', '-q', '-B', $branch, "refs/remotes/origin/{$branch}"]);

        if ($code !== 0) {
            throw new ToolError(str_contains($error, 'would be overwritten')
                ? 'Files in the project would be overwritten by the repository. Commit or remove them, then pull again.'
                : 'Could not bring in the repository: '.trim(preg_replace('/^(fatal|error): /m', '', $error)));
        }

        return status();
    }

    [$code, , $error] = git(['merge', '--ff-only', '-q', "refs/remotes/origin/{$branch}"]);

    if ($code !== 0) {
        throw new ToolError(str_contains($error, 'Not possible to fast-forward') || str_contains($error, 'diverging')
            ? 'This branch and the remote both have new commits. Ask the agent to merge them.'
            : 'Could not bring in the remote\'s changes: '.trim(preg_replace('/^(fatal|error): /m', '', $error)));
    }

    return status();
}

try {
    $request = json_decode(getenv('APP_GIT_REQUEST') ?: '', true);

    if (! is_array($request)) {
        throw new ToolError('Invalid request.');
    }

    $data = match ($request['op'] ?? null) {
        'status' => status(),
        'log' => history($request),
        'show' => showCommit($request),
        'diff' => diff($request),
        'commit' => commit($request),
        'discard' => discard($request),
        'switch' => switchBranch($request),
        'restore' => restore($request),
        'pushed' => pushed($request),
        'pulled' => pulled($request),
        default => throw new ToolError('Unknown operation.'),
    };

    respond(['ok' => true, 'data' => $data]);
} catch (ToolError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'The Git tool failed: '.$e->getMessage()]);
}
