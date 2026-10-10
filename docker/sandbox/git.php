<?php

/**
 * Git tool behind the workspace's Source Control tab: the app's branch, staged and unstaged changes, history (and each
 * commit's files and diffs), staging files, hunks and lines, committing, discarding, switching branches, restoring an
 * earlier version (as a new commit), and the agent's private checkpoints (the Timeline) and restoring one.
 *
 * Remotes are handled by the platform, never here: pushes and pulls travel as git bundles, so the remote's
 * credentials stay out of the sandbox. This only records what the platform pushed or fetched.
 *
 * Reads one JSON request from $APP_GIT_REQUEST and prints one JSON response:
 * {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 *
 * Usage: APP_GIT_REQUEST='{"op":"status"}' php /opt/onedrop/git.php
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const CHANGES_MAX = 500;
const LOG_MAX = 200;
const FILES_MAX = 300;
const DIFF_BYTES_MAX = 200_000;
const NEW_FILE_BYTES_MAX = 1_000_000;
const CHANGES_DIFF_BYTES_MAX = 30_000;
const COMPARE_COMMITS_MAX = 100;
const COMBINE_COMMITS_MAX = 200;
const EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
const AGENT_NAME = 'OneDrop';
const CHECKPOINTS = 'refs/onedrop/checkpoints';
const CHECKPOINTS_MAX = 100;
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

    return ['ONEDROP_GIT_AUTHOR_NAME' => $name, 'ONEDROP_GIT_AUTHOR_EMAIL' => $email];
}

/**
 * Run /opt/onedrop/checkpoint with $mode (--commit, --snapshot or --prepare) and $message on stdin. Returns what it
 * printed.
 *
 * @param  array<string, string>  $env
 */
function checkpointScript(string $mode, string $message = '', array $env = []): string
{
    $script = getenv('APP_CHECKPOINT') ?: '/opt/onedrop/checkpoint';
    $process = proc_open([$script, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, workspace(), [...getenv(), 'ONEDROP_WORKSPACE' => workspace(), ...$env]);

    if (! is_resource($process)) {
        throw new ToolError('Could not run git.');
    }

    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return trim($output);
}

/**
 * Start the repository if there's none, and keep dependencies and secrets out of it.
 */
function prepare(): void
{
    checkpointScript('--prepare');
}

/**
 * Commit everything (dependencies and secrets stay out). Returns whether it committed.
 *
 * @param  array<string, string>  $author
 */
function commitAll(string $message, array $author): bool
{
    $before = head();
    checkpointScript('--commit', $message, $author);

    return head() !== $before;
}

/**
 * Save every file as a private checkpoint (refs/onedrop/checkpoints) when anything changed since the last one; returns
 * the newest checkpoint, or the branch's commit when there's none.
 */
function snapshot(string $message, string $kind = 'edits'): ?string
{
    $sha = checkpointScript('--snapshot', $message, ['ONEDROP_CHECKPOINT_KIND' => $kind]);

    return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
}

/**
 * Staged and unstaged changes: path, a one-letter state (M modified, A added, D deleted, R renamed, U conflicted,
 * ? new), the lines added and removed (null for binary files and new folders), and for renames the path it came from.
 * A file staged and then edited again is in both lists. Conflicted and new files are unstaged.
 *
 * @return array{list<array{path: string, status: string, additions: ?int, deletions: ?int, binary: bool, from?: string}>, list<array{path: string, status: string, additions: ?int, deletions: ?int, binary: bool, from?: string}>, bool}
 */
function changes(): array
{
    $output = gitOrFail(['status', '--porcelain=v1', '-z', '--untracked-files=normal']);
    $entries = $output === '' ? [] : explode("\0", rtrim($output, "\0"));
    $staged = [];
    $unstaged = [];

    for ($i = 0; $i < count($entries); $i++) {
        $entry = $entries[$i];
        [$index, $worktree] = [$entry[0], $entry[1]];
        $path = substr($entry, 3);
        $from = null;

        // Renames and copies are followed by their original path.
        if ($index === 'R' || $index === 'C') {
            $from = $entries[++$i] ?? null;
        }

        if ($index === '?') {
            $unstaged[] = ['path' => $path, 'status' => '?'];

            continue;
        }

        if ($index === 'U' || $worktree === 'U' || ($index === 'A' && $worktree === 'A') || ($index === 'D' && $worktree === 'D')) {
            $unstaged[] = ['path' => $path, 'status' => 'U'];

            continue;
        }

        if ($index !== ' ') {
            $status = match ($index) {
                'R' => 'R',
                'C', 'A' => 'A',
                'D' => 'D',
                default => 'M',
            };
            $staged[] = ['path' => $path, 'status' => $status, ...($status === 'R' && $from !== null ? ['from' => $from] : [])];
        }

        if ($worktree !== ' ') {
            $unstaged[] = ['path' => $path, 'status' => $worktree === 'D' ? 'D' : 'M'];
        }
    }

    return [
        withLineCounts(array_slice($staged, 0, CHANGES_MAX), staged: true),
        withLineCounts(array_slice($unstaged, 0, CHANGES_MAX), staged: false),
        count($staged) > CHANGES_MAX || count($unstaged) > CHANGES_MAX,
    ];
}

/**
 * Every change, staged or not, once per file: what committing everything would commit.
 *
 * @return list<array{path: string, status: string, additions: ?int, deletions: ?int, binary: bool, from?: string}>
 */
function allChanges(): array
{
    [$staged, $unstaged] = changes();

    return array_values(array_column([...$unstaged, ...$staged], null, 'path'));
}

/**
 * Add each change's lines added and removed: staged ones since the last commit, unstaged ones since they were staged.
 *
 * @param  list<array{path: string, status: string, from?: string}>  $changes
 * @return list<array{path: string, status: string, additions: ?int, deletions: ?int, binary: bool, from?: string}>
 */
function withLineCounts(array $changes, bool $staged): array
{
    if ($changes === []) {
        return [];
    }

    $counts = [];
    $args = $staged ? ['diff', '--cached', '--numstat', '-z', '--no-renames', ...(head() === null ? [] : ['HEAD'])] : ['diff', '--numstat', '-z', '--no-renames'];

    foreach (array_filter(explode("\0", git($args)[1])) as $line) {
        [$added, $removed, $path] = array_pad(explode("\t", $line, 3), 3, '');
        $counts[$path] = $added === '-' ? [null, null, true] : [(int) $added, (int) $removed, false];
    }

    return array_map(function (array $change) use ($counts) {
        [$additions, $deletions, $binary] = $counts[$change['path']] ?? ($change['status'] === '?' ? newFileLines($change['path']) : [null, null, false]);

        return [...$change, 'additions' => $additions, 'deletions' => $deletions, 'binary' => $binary];
    }, $changes);
}

/**
 * A new file's lines (git doesn't count untracked files): [added, removed, binary]. New folders aren't counted.
 *
 * @return array{?int, ?int, bool}
 */
function newFileLines(string $path): array
{
    $file = workspace().'/'.$path;

    if (! is_file($file) || filesize($file) > NEW_FILE_BYTES_MAX) {
        return [null, null, false];
    }

    $content = (string) file_get_contents($file);

    if (str_contains($content, "\0")) {
        return [null, null, true];
    }

    return [$content === '' ? 0 : substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1), 0, false];
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
        return ['initialized' => false, 'branch' => null, 'branches' => [], 'head' => null, 'staged' => [], 'changes' => [], 'more_changes' => false, 'tracking' => null, 'state' => null];
    }

    $branch = currentBranch();
    [$staged, $changes, $more] = changes();
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
        'staged' => $staged,
        'changes' => $changes,
        'more_changes' => $more,
        'tracking' => tracking($branch),
        'unpushed' => unpushedCount(),
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
        'body' => trim(preg_replace('/^Onedrop-[A-Za-z]+: .*$/m', '', $body)),
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

/**
 * Commit what's staged as the user, or every change when nothing is (dependencies and secrets stay out). Mid-merge,
 * committing what's staged finishes the merge.
 */
function commit(array $request): array
{
    $message = is_string($request['message'] ?? null) ? trim($request['message']) : '';

    if ($message === '') {
        throw new ToolError('Write a message for the commit.');
    }

    if (strlen($message) > 5000) {
        throw new ToolError('That message is too long.');
    }

    prepare();
    $author = authorEnv($request);
    [$staged] = changes();

    if ($staged === [] && status()['state'] !== null) {
        throw new ToolError('Stage the files you\'ve resolved, then commit.');
    }

    if ($staged === []) {
        if (! commitAll($message, $author)) {
            throw new ToolError('There\'s nothing to commit.');
        }

        return status();
    }

    [$code, $error] = commitStaged($message, $author);

    if ($code !== 0) {
        throw new ToolError(str_contains($error, 'unmerged')
            ? 'Some files still have conflicts. Resolve and stage them, then commit.'
            : 'Could not commit: '.trim(preg_replace('/^(fatal|error): /m', '', $error)));
    }

    return status();
}

/**
 * Commit what's staged with $message, as $author. Returns [exit code, git's error].
 *
 * @param  array<string, string>  $author
 * @return array{int, string}
 */
function commitStaged(string $message, array $author): array
{
    [$code, , $error] = git(['-c', 'commit.gpgsign=false', 'commit', '-q', '--no-verify', '-m', $message], [
        'GIT_AUTHOR_NAME' => $author['ONEDROP_GIT_AUTHOR_NAME'],
        'GIT_AUTHOR_EMAIL' => $author['ONEDROP_GIT_AUTHOR_EMAIL'],
        'GIT_COMMITTER_NAME' => $author['ONEDROP_GIT_AUTHOR_NAME'],
        'GIT_COMMITTER_EMAIL' => $author['ONEDROP_GIT_AUTHOR_EMAIL'],
    ]);

    return [$code, $error];
}

/**
 * The paths a request names ("paths"; null for every change), each with where a staged rename came from.
 *
 * @param  list<array{path: string, status: string, from?: string}>  $changes
 * @return list<string>|null
 */
function chosenPaths(array $request, array $changes): ?array
{
    $paths = $request['paths'] ?? null;

    if ($paths === null) {
        return null;
    }

    if (! is_array($paths) || $paths === []) {
        throw new ToolError('Pick at least one file.');
    }

    $byPath = array_column($changes, null, 'path');
    $chosen = [];

    foreach ($paths as $path) {
        if (! is_string($path) || ! isset($byPath[$path])) {
            throw new ToolError('Some of those files have no changes.');
        }

        $chosen[] = $path;

        if (isset($byPath[$path]['from'])) {
            $chosen[] = $byPath[$path]['from'];
        }
    }

    return array_values(array_unique($chosen));
}

/**
 * Stage unstaged changes: the files at "paths", or every one.
 */
function stage(array $request): array
{
    prepare();
    [, $unstaged] = changes();

    if (in_array('U', array_column($unstaged, 'status'), true) && ($request['paths'] ?? null) === null) {
        // Staging a conflicted file marks it resolved; only when it's picked on purpose.
        $unstaged = array_values(array_filter($unstaged, fn (array $change) => $change['status'] !== 'U'));

        if ($unstaged === []) {
            throw new ToolError('Only conflicted files are left. Resolve each one, then stage it.');
        }

        $paths = array_column($unstaged, 'path');
    } else {
        $paths = chosenPaths($request, $unstaged) ?? ['.'];
    }

    gitOrFail(['add', '-A', '--', ...$paths]);

    return status();
}

/**
 * Unstage staged changes: the files at "paths", or every one. The files themselves don't change.
 */
function unstage(array $request): array
{
    [$staged] = changes();
    $paths = chosenPaths($request, $staged) ?? ['.'];

    if (head() === null) {
        gitOrFail(['rm', '-r', '-q', '--cached', '--ignore-unmatch', '--', ...$paths]);
    } else {
        gitOrFail(['restore', '--staged', '--', ...$paths]);
    }

    return status();
}

/**
 * Stage or unstage ("staged": true unstages) one hunk ("hunk") or chosen lines ("lines", indexes in the patch) of an
 * edited file, checking its diff is still the one shown ("hash").
 */
function stageLines(array $request): array
{
    $staged = ($request['staged'] ?? false) === true;
    $change = changeAt($request['path'] ?? null, $staged);

    if ($change['status'] !== 'M' || $change['binary']) {
        throw new ToolError('Only parts of edited text files can be staged.');
    }

    $patch = changePatch($change, $staged);

    if (! is_string($request['hash'] ?? null) || ! hash_equals(sha1($patch), $request['hash'])) {
        throw new ToolError('This file changed. Review it again.');
    }

    $hunk = is_int($request['hunk'] ?? null) ? $request['hunk'] : null;
    $lines = is_array($request['lines'] ?? null) ? array_values(array_filter($request['lines'], 'is_int')) : null;

    if ($hunk === null && $lines === null) {
        throw new ToolError('Pick a part of the file.');
    }

    $part = partialPatch($patch, $lines === null ? [] : unchosenLines($patch, $lines), onlyHunk: $hunk, reverse: $staged);

    if ($part === '') {
        throw new ToolError('That part has no changes.');
    }

    [$code] = git(['apply', '--cached', ...($staged ? ['-R'] : []), '--recount', '--whitespace=nowarn', '-'], input: $part);

    if ($code !== 0) {
        throw new ToolError('This file changed. Review it again.');
    }

    return status();
}

/**
 * Put one hunk of an edited file back as it was staged (or in the last commit), checking the file is still as it
 * was shown.
 */
function discardHunk(array $request): array
{
    $change = changeAt($request['path'] ?? null, false);

    if ($change['status'] !== 'M' || $change['binary']) {
        throw new ToolError('Only parts of edited text files can be discarded.');
    }

    $patch = changePatch($change, false);

    if (! is_string($request['hash'] ?? null) || ! hash_equals(sha1($patch), $request['hash'])) {
        throw new ToolError('This file changed. Review it again.');
    }

    $hunk = partialPatch($patch, [], onlyHunk: is_int($request['hunk'] ?? null) ? $request['hunk'] : -1);

    if ($hunk === '') {
        throw new ToolError('That part has no changes.');
    }

    snapshot('Before discarding a change');
    [$code] = git(['apply', '-R', '--recount', '--whitespace=nowarn', '-'], input: $hunk);

    if ($code !== 0) {
        throw new ToolError('This file changed. Review it again.');
    }

    return status();
}

/**
 * Whether the platform has pushed or fetched anything, so there are remote branches to compare with.
 */
function hasRemoteBranches(): bool
{
    return trim(git(['for-each-ref', '--count=1', '--format=%(refname)', 'refs/remotes/origin'])[1]) !== '';
}

/**
 * How many commits on the current branch no remote branch has yet, or null without any remote branches.
 */
function unpushedCount(): ?int
{
    if (head() === null || ! hasRemoteBranches()) {
        return null;
    }

    return (int) trim(git(['rev-list', '--count', 'HEAD', '--not', '--remotes=origin'])[1]);
}

/**
 * The commits on the current branch no remote branch has yet (newest first), and the commit they start from.
 * Combining is only for those, so a push never has to overwrite the remote.
 *
 * @return array{list<array{sha: string, subject: string, author: string, email: string, date: string, agent: bool}>, string}
 */
function unpushedCommits(): array
{
    if (! isRepository() || head() === null || ! hasRemoteBranches()) {
        throw new ToolError('Push the branch once first; then commits made after that can be combined.');
    }

    $commits = logCommits(['--max-count='.(COMBINE_COMMITS_MAX + 1), 'HEAD', '--not', '--remotes=origin']);

    if (count($commits) < 2) {
        throw new ToolError('There\'s only one commit to push, so there\'s nothing to combine.');
    }

    if (count($commits) > COMBINE_COMMITS_MAX) {
        throw new ToolError('That\'s too many commits to combine at once.');
    }

    if (trim(git(['rev-list', '--merges', '--count', 'HEAD', '--not', '--remotes=origin'])[1]) !== '0') {
        throw new ToolError('These commits include a merge, so they can\'t be combined here.');
    }

    [$code, $base] = git(['rev-parse', '--verify', '-q', end($commits)['sha'].'^']);

    if ($code !== 0) {
        throw new ToolError('These commits start the history, so there\'s nothing to combine them onto.');
    }

    return [$commits, trim($base)];
}

/**
 * The commits that would be combined and the diff they make together, cut short, for writing the message.
 */
function combinePreview(): array
{
    [$commits, $base] = unpushedCommits();
    $patch = git(['diff', '--no-color', '--no-ext-diff', $base, 'HEAD'])[1];

    return [
        'commits' => $commits,
        'patch' => substr($patch, 0, CHANGES_DIFF_BYTES_MAX),
        'truncated' => strlen($patch) > CHANGES_DIFF_BYTES_MAX,
    ];
}

/**
 * Combine the commits no remote has yet into one, as the user. The files stay exactly as they are.
 */
function combine(array $request): array
{
    $message = is_string($request['message'] ?? null) ? trim($request['message']) : '';

    if ($message === '') {
        throw new ToolError('Write a message for the commit.');
    }

    [$staged] = changes();

    if ($staged !== []) {
        throw new ToolError('Commit or unstage your staged changes first.');
    }

    if (status()['state'] !== null) {
        throw new ToolError('Finish the merge or rebase first.');
    }

    [, $base] = unpushedCommits();
    $before = head();
    gitOrFail(['reset', '--soft', '-q', $base]);
    $author = authorEnv($request);

    // Only what the commits held is staged now; unstaged changes stay as they are.
    [$code, $error] = commitStaged($message, $author);

    if ($code !== 0) {
        // Nothing came of it (the commits cancelled each other out, or the commit failed): put them back.
        gitOrFail(['reset', '--soft', '-q', $before]);

        throw new ToolError('Those commits add up to no changes, so they weren\'t combined.');
    }

    return status();
}

/**
 * Undo the last commit (it must be "sha", the one that was shown): its changes come back as uncommitted, and the
 * files stay exactly as they are. Only for a commit no remote has yet, so a push never has to overwrite it.
 */
function undoCommit(array $request): array
{
    $sha = validCommit($request['sha'] ?? null);

    if (! isRepository() || head() === null || ! str_starts_with(head(), $sha)) {
        throw new ToolError('The last commit changed. Look again before undoing it.');
    }

    if (status()['state'] !== null) {
        throw new ToolError('Finish the merge or rebase first.');
    }

    $parents = array_values(array_filter(explode(' ', trim(gitOrFail(['log', '-1', '--format=%P', 'HEAD'])))));

    if ($parents === []) {
        throw new ToolError('That\'s the first commit, so there\'s nothing before it to go back to.');
    }

    if (count($parents) > 1) {
        throw new ToolError('That\'s a merge, so it can\'t be undone here.');
    }

    if (hasRemoteBranches() && trim(git(['branch', '-r', '--contains', 'HEAD'])[1]) !== '') {
        throw new ToolError('That commit is already pushed, so undoing it here would leave the repository behind. Restore the version before it instead.');
    }

    gitOrFail(['reset', '-q', 'HEAD~1']);

    return status();
}

/**
 * The ref a base branch is compared against: what the platform last fetched or pushed of it, or else the local one.
 */
function baseRef(mixed $base): string
{
    $base = validBranch($base);

    foreach (["refs/remotes/origin/{$base}", "refs/heads/{$base}"] as $ref) {
        if (git(['rev-parse', '--verify', '-q', $ref])[0] === 0) {
            return $ref;
        }
    }

    throw new ToolError('That base branch isn\'t in this project.');
}

/**
 * What the current branch has that "base" doesn't, for describing a pull request: its commits (newest first)
 * and its diff since they parted, cut short.
 */
function compare(array $request): array
{
    if (! isRepository() || head() === null) {
        throw new ToolError('There are no commits yet.');
    }

    $ref = baseRef($request['base'] ?? null);
    $commits = logCommits(['--max-count='.(COMPARE_COMMITS_MAX + 1), "{$ref}..HEAD"]);
    $patch = git(['diff', '--no-color', '--no-ext-diff', "{$ref}...HEAD"])[1];

    return [
        'base' => $request['base'],
        'commits' => array_slice($commits, 0, COMPARE_COMMITS_MAX),
        'more' => count($commits) > COMPARE_COMMITS_MAX,
        'patch' => substr($patch, 0, CHANGES_DIFF_BYTES_MAX),
        'truncated' => strlen($patch) > CHANGES_DIFF_BYTES_MAX,
    ];
}

/**
 * The staged or unstaged change at "path".
 *
 * @return array{path: string, status: string, additions: ?int, deletions: ?int, binary: bool, from?: string}
 */
function changeAt(mixed $path, bool $staged): array
{
    $path = validPath($path);
    [$stagedChanges, $unstaged] = isRepository() ? changes() : [[], []];
    $change = array_column($staged ? $stagedChanges : $unstaged, null, 'path')[$path] ?? null;

    if ($change === null) {
        throw new ToolError($staged ? 'That file has no staged changes.' : 'That file has no unstaged changes.');
    }

    return $change;
}

/**
 * A text change's whole patch: a staged one's since the last commit, an unstaged one's since it was staged. A new
 * file's is all added lines, with the header git needs to apply it.
 */
function changePatch(array $change, bool $staged): string
{
    $path = $change['path'];

    if ($change['status'] !== '?') {
        $paths = isset($change['from']) ? [$change['from'], $path] : [$path];
        $args = $staged ? ['diff', '--cached', ...(head() === null ? [] : ['HEAD'])] : ['diff'];

        return git([...$args, '--no-color', '--no-ext-diff', '--no-renames', '--', ...$paths])[1];
    }

    $full = workspace().'/'.$path;
    $content = (string) file_get_contents($full);
    $lines = $content === '' ? [] : explode("\n", str_ends_with($content, "\n") ? substr($content, 0, -1) : $content);
    $mode = is_executable($full) ? '100755' : '100644';

    return "diff --git a/{$path} b/{$path}\nnew file mode {$mode}\n--- /dev/null\n+++ b/{$path}\n"
        .($lines === [] ? '' : '@@ -0,0 +1,'.count($lines)." @@\n".implode('', array_map(fn (string $line) => "+{$line}\n", $lines)))
        .($content !== '' && ! str_ends_with($content, "\n") ? "\\ No newline at end of file\n" : '');
}

/**
 * One change's patch ("staged": its staged part, otherwise its unstaged part), cut short when it's very large, with a
 * hash of the whole patch (so staging parts of it can check it's still what was shown). Binary files are only marked,
 * and a new folder lists its files instead.
 *
 * @return array{path: string, staged: bool, patch: string, hash: ?string, truncated: bool, binary: bool, files: list<string>|null}
 */
function changeDiff(array $request): array
{
    $staged = ($request['staged'] ?? false) === true;
    $change = changeAt($request['path'] ?? null, $staged);
    $path = $change['path'];
    $result = ['path' => $path, 'staged' => $staged, 'patch' => '', 'hash' => null, 'truncated' => false, 'binary' => false, 'files' => null];

    if ($change['status'] === '?' && is_dir(workspace().'/'.rtrim($path, '/'))) {
        $files = array_values(array_filter(explode("\0", git(['ls-files', '-z', '--others', '--exclude-standard', '--', $path])[1])));

        return [...$result, 'files' => array_slice($files, 0, FILES_MAX), 'truncated' => count($files) > FILES_MAX];
    }

    if ($change['binary']) {
        return [...$result, 'binary' => true];
    }

    // A very large new file: show its start, without reading it all.
    if ($change['status'] === '?' && filesize(workspace().'/'.$path) > DIFF_BYTES_MAX) {
        $start = explode("\n", (string) file_get_contents(workspace().'/'.$path, length: DIFF_BYTES_MAX));
        array_pop($start);

        return [...$result, 'truncated' => true, 'patch' => '@@ -0,0 +1,'.count($start)." @@\n".implode('', array_map(fn (string $line) => "+{$line}\n", $start))];
    }

    $patch = changePatch($change, $staged);

    return [...$result, 'patch' => substr($patch, 0, DIFF_BYTES_MAX), 'hash' => sha1($patch), 'truncated' => strlen($patch) > DIFF_BYTES_MAX];
}

/**
 * The indexes of a patch's added and removed lines that aren't in $chosen.
 *
 * @param  list<int>  $chosen
 * @return list<int>
 */
function unchosenLines(string $patch, array $chosen): array
{
    $chosen = array_flip($chosen);
    $inHunk = false;
    $unchosen = [];

    foreach (explode("\n", $patch) as $index => $line) {
        $inHunk = $inHunk || str_starts_with($line, '@@');

        if ($inHunk && ($line[0] ?? '') !== '' && in_array($line[0], ['+', '-'], true) && ! isset($chosen[$index])) {
            $unchosen[] = $index;
        }
    }

    return $unchosen;
}

/**
 * A patch with some of its lines left out (their indexes in the patch's lines): a left-out added line is dropped,
 * and a left-out removed line stays as it was. With $reverse (for applying it backwards, to unstage), the other way
 * round: a left-out removed line is dropped and a left-out added line stays. With $onlyHunk, every hunk but that one
 * is left out. Hunks with no changes left are dropped; '' when none are left. Line counts are left to
 * `git apply --recount`.
 *
 * @param  list<int>  $excluded
 */
function partialPatch(string $patch, array $excluded, ?int $onlyHunk = null, bool $reverse = false): string
{
    $lines = explode("\n", $patch);

    if (end($lines) === '') {
        array_pop($lines);
    }

    $excluded = array_flip($excluded);
    $header = [];
    $hunks = [];

    foreach ($lines as $index => $line) {
        if (str_starts_with($line, '@@')) {
            $hunks[] = ['header' => $line, 'lines' => []];
        } elseif ($hunks === []) {
            $header[] = $line;
        } else {
            $hunks[count($hunks) - 1]['lines'][$index] = $line;
        }
    }

    $out = $header;
    $kept = false;

    foreach ($hunks as $number => $hunk) {
        if ($onlyHunk !== null && $number !== $onlyHunk) {
            continue;
        }

        $body = [];
        $changed = false;
        $dropped = false;

        foreach ($hunk['lines'] as $index => $line) {
            $kind = $line === '' ? ' ' : $line[0];

            // "\ No newline at end of file" belongs to the line before it.
            if ($kind === '\\') {
                if (! $dropped) {
                    $body[] = $line;
                }

                continue;
            }

            $dropped = false;

            if (isset($excluded[$index]) && $kind === ($reverse ? '-' : '+')) {
                $dropped = true;

                continue;
            }

            if (isset($excluded[$index]) && $kind === ($reverse ? '+' : '-')) {
                $body[] = ' '.substr($line, 1);

                continue;
            }

            $changed = $changed || $kind === '+' || $kind === '-';
            $body[] = $line;
        }

        if ($changed) {
            array_push($out, $hunk['header'], ...$body);
            $kept = true;
        }
    }

    return $kept ? implode("\n", $out)."\n" : '';
}

/**
 * What committing would commit (what's staged, or every change when nothing is), cut short for a model to
 * summarize: the tracked files' diff and the new files' names.
 */
function changesDiff(): array
{
    if (! isRepository()) {
        return ['patch' => '', 'new_files' => [], 'truncated' => false];
    }

    [$staged, $unstaged] = changes();
    $base = head() === null ? [] : ['HEAD'];
    $patch = $staged !== []
        ? git(['diff', '--cached', ...$base, '--no-color', '--no-ext-diff', '--no-renames'])[1]
        : git(['diff', ...($base === [] ? ['--cached'] : $base), '--no-color', '--no-ext-diff', '--no-renames'])[1];
    $newFiles = $staged !== []
        ? array_column(array_filter($staged, fn (array $change) => $change['status'] === 'A'), 'path')
        : array_column(array_filter($unstaged, fn (array $change) => $change['status'] === '?'), 'path');

    return [
        'patch' => substr($patch, 0, CHANGES_DIFF_BYTES_MAX),
        'new_files' => array_values($newFiles),
        'truncated' => strlen($patch) > CHANGES_DIFF_BYTES_MAX,
    ];
}

/**
 * Throw away unstaged changes, to the files at "paths" or every one: each goes back to how it's staged (or to the last
 * commit), and new files are deleted. Staged changes and ignored files (node_modules, .env) stay. The files as they
 * were are saved as a checkpoint first.
 */
function discard(array $request): array
{
    if (! isRepository()) {
        throw new ToolError('There\'s nothing to discard.');
    }

    [, $unstaged] = changes();
    $paths = chosenPaths($request, $unstaged);
    $chosen = $paths === null ? $unstaged : array_values(array_filter($unstaged, fn (array $change) => in_array($change['path'], $paths, true)));

    if (in_array('U', array_column($chosen, 'status'), true)) {
        if ($paths !== null) {
            throw new ToolError('Resolve the conflict in that file, or ask the agent to.');
        }

        $chosen = array_values(array_filter($chosen, fn (array $change) => $change['status'] !== 'U'));
    }

    // The Timeline keeps what's thrown away.
    prepare();
    snapshot('Before discarding changes');

    $new = array_column(array_filter($chosen, fn (array $change) => $change['status'] === '?'), 'path');
    $tracked = array_column(array_filter($chosen, fn (array $change) => $change['status'] !== '?'), 'path');

    if ($tracked !== []) {
        gitOrFail(['restore', '--worktree', '--', ...$tracked]);
    }

    if ($new !== []) {
        gitOrFail(['clean', '-f', '-d', '-q', '--', ...$new]);
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
 * Make the workspace match an earlier commit, as a new commit on top (nothing is lost): uncommitted changes are saved
 * as a private checkpoint first (the Timeline has them), then every file is put back as it was.
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

    prepare();
    snapshot("Before restoring {$short}");
    // New files are staged first, so restoring removes the ones the commit didn't have.
    gitOrFail(['add', '-A', '.']);
    gitOrFail(['restore', "--source={$sha}", '--staged', '--worktree', '--', ':/']);

    [$code, $error] = commitStaged("Restore \"{$subject}\" ({$short})", $author);

    if ($code !== 0) {
        throw new ToolError(str_contains($error, 'nothing') ? 'The files are already as they were then.' : 'Could not commit: '.trim($error));
    }

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
        throw new ToolError(match (true) {
            str_contains($error, 'Not possible to fast-forward') || str_contains($error, 'diverging') => 'This branch and the remote both have new commits. Ask the agent to merge them.',
            str_contains($error, 'would be overwritten') => 'Your uncommitted changes to some of the same files would be overwritten. Commit or discard them, then pull again.',
            default => 'Could not bring in the remote\'s changes: '.trim(preg_replace('/^(fatal|error): /m', '', $error)),
        });
    }

    return status();
}

/**
 * The private checkpoints (refs/onedrop/checkpoints), newest first, a page at a time ("offset"): each agent turn,
 * edits made outside the agent, restores and applied tasks, with the files they changed.
 */
function checkpoints(array $request): array
{
    if (! isRepository() || git(['rev-parse', '--verify', '-q', CHECKPOINTS])[0] !== 0) {
        return ['checkpoints' => [], 'more' => false];
    }

    $offset = max(0, min(100_000, (int) ($request['offset'] ?? 0)));
    $output = gitOrFail(['log', '--first-parent', '--shortstat', '--skip='.$offset, '--max-count='.(CHECKPOINTS_MAX + 1), '--format=%x1e%H%x1f%s%x1f%aI%x1f%(trailers:key=Onedrop-Kind,valueonly,separator=)%x1f%P', CHECKPOINTS]);
    $checkpoints = [];

    foreach (array_filter(explode("\x1e", $output), fn (string $record) => trim($record) !== '') as $record) {
        [$header, $stat] = array_pad(explode("\n", trim($record, "\n"), 2), 2, '');
        [$sha, $subject, $date, $kind, $parents] = explode("\x1f", $header);

        // The first checkpoint's parent is the branch's commit: the history before it isn't the Timeline's.
        if (trim($kind) === '') {
            break;
        }

        preg_match('/(\d+) files? changed/', $stat, $files);
        preg_match('/(\d+) insertions?/', $stat, $added);
        preg_match('/(\d+) deletions?/', $stat, $removed);

        $checkpoints[] = [
            'sha' => $sha,
            'subject' => $subject,
            'date' => $date,
            'kind' => trim($kind),
            'files' => (int) ($files[1] ?? 0),
            'additions' => (int) ($added[1] ?? 0),
            'deletions' => (int) ($removed[1] ?? 0),
            'restorable_before' => trim($parents) !== '',
        ];
    }

    return ['checkpoints' => array_slice($checkpoints, 0, CHECKPOINTS_MAX), 'more' => count($checkpoints) > CHECKPOINTS_MAX];
}

/**
 * Put every file back as it was at a checkpoint ("sha"), or just before it ("before"). Nothing is committed and what's
 * staged stays staged: the difference shows as unstaged changes. The files as they were are saved first, so the
 * restore can itself be undone from the Timeline.
 */
function restoreCheckpoint(array $request): array
{
    $sha = validCommit($request['sha'] ?? null);
    $kind = trim(gitOrFail(['log', '-1', '--format=%(trailers:key=Onedrop-Kind,valueonly,separator=)', $sha]));

    if ($kind === '') {
        throw new ToolError('That isn\'t one of the project\'s checkpoints.');
    }

    $before = ($request['before'] ?? false) === true;
    $target = $sha;

    if ($before) {
        [$code, $parent] = git(['rev-parse', '--verify', '-q', "{$sha}^"]);

        if ($code !== 0) {
            throw new ToolError('Nothing came before that checkpoint.');
        }

        $target = trim($parent);
    }

    $subject = trim(gitOrFail(['log', '-1', '--format=%s', $sha]));
    $current = snapshot('Before restoring a checkpoint');

    if ($current === null) {
        throw new ToolError('Could not save the files as they are.');
    }

    putTree($current, $target);
    snapshot(($before ? 'Restore before "' : 'Restore "').mb_strimwidth($subject, 0, 60, '…').'"', 'restore');

    return status();
}

/**
 * Make the files go from commit $from to commit $to: write $to's files, and remove those $from has and $to doesn't.
 * The index (what's staged) and ignored files are untouched.
 */
function putTree(string $from, string $to): void
{
    $index = workspace().'/.git/onedrop-put-index';
    @unlink($index);

    try {
        gitOrFail(['read-tree', $to], ['GIT_INDEX_FILE' => $index]);
        gitOrFail(['checkout-index', '-a', '-f'], ['GIT_INDEX_FILE' => $index]);
    } finally {
        @unlink($index);
    }

    foreach (array_filter(explode("\0", gitOrFail(['diff', '--name-only', '-z', '--no-renames', '--diff-filter=D', $from, $to]))) as $path) {
        @unlink(workspace().'/'.$path);
    }
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
        'stage' => stage($request),
        'unstage' => unstage($request),
        'stage_lines' => stageLines($request),
        'changes_diff' => changesDiff(),
        'change_diff' => changeDiff($request),
        'discard_hunk' => discardHunk($request),
        'compare' => compare($request),
        'combine_preview' => combinePreview(),
        'combine' => combine($request),
        'undo_commit' => undoCommit($request),
        'discard' => discard($request),
        'switch' => switchBranch($request),
        'restore' => restore($request),
        'checkpoints' => checkpoints($request),
        'restore_checkpoint' => restoreCheckpoint($request),
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
