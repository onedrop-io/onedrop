<?php

namespace App\Sandbox;

use App\Models\Sandbox;
use App\Models\User;

/**
 * The app's git repository (branch, staged and unstaged changes, history, commits, restores, and the agent's private
 * checkpoints), reached through docker/sandbox/git.php inside the sandbox. Remotes are handled by GitRemote on the platform, so their credentials never enter the sandbox.
 */
class WorkspaceGit
{
    public const SCRIPT = '/opt/onedrop/git.php';

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * @return array{initialized: bool, branch: ?string, branches: list<string>, head: ?string, staged: list<array{path: string, status: string, additions?: ?int, deletions?: ?int, binary?: bool, from?: string}>, changes: list<array{path: string, status: string, additions?: ?int, deletions?: ?int, binary?: bool, from?: string}>, more_changes: bool, tracking: array{ahead: int, behind: int}|null, unpushed?: ?int, state: ?string}
     *
     * @throws SandboxException|GitException
     */
    public function status(Sandbox $sandbox): array
    {
        // A sandbox whose Git tool predates staging (SCM-001) reports everything as unstaged until it's updated.
        return ['staged' => [], ...$this->call($sandbox, ['op' => 'status'])];
    }

    /**
     * The newest commits.
     *
     * @return list<array{sha: string, subject: string, author: string, email: string, date: string, agent: bool}>
     *
     * @throws SandboxException|GitException
     */
    public function log(Sandbox $sandbox, int $limit = 50): array
    {
        return $this->history($sandbox, limit: $limit)['commits'];
    }

    /**
     * A page of commits, newest first, optionally only those matching $query (message, author, or id), and
     * whether there are more.
     *
     * @return array{commits: list<array{sha: string, subject: string, author: string, email: string, date: string, agent: bool}>, more: bool}
     *
     * @throws SandboxException|GitException
     */
    public function history(Sandbox $sandbox, ?string $query = null, int $offset = 0, int $limit = 50): array
    {
        return $this->call($sandbox, ['op' => 'log', 'query' => $query, 'offset' => $offset, 'limit' => $limit]);
    }

    /**
     * One commit in full, with the files it changed.
     *
     * @return array{sha: string, subject: string, body: string, author: string, email: string, date: string, agent: bool, parents: list<string>, files: list<array{path: string, status: string, additions: ?int, deletions: ?int, binary: bool}>, more_files: bool}
     *
     * @throws SandboxException|GitException
     */
    public function show(Sandbox $sandbox, string $sha): array
    {
        return $this->call($sandbox, ['op' => 'show', 'sha' => $sha]);
    }

    /**
     * The patch a commit made to one file.
     *
     * @return array{path: string, patch: string, truncated: bool}
     *
     * @throws SandboxException|GitException
     */
    public function diff(Sandbox $sandbox, string $sha, string $path): array
    {
        return $this->call($sandbox, ['op' => 'diff', 'sha' => $sha, 'path' => $path]);
    }

    /**
     * Commit what's staged, or every change when nothing is (dependencies and secrets excluded), as the user.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function commit(Sandbox $sandbox, string $message, User $user): array
    {
        return $this->call($sandbox, ['op' => 'commit', 'message' => $message, ...$this->author($user)]);
    }

    /**
     * Stage the unstaged changes to $paths, or every one.
     *
     * @param  list<string>|null  $paths
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function stage(Sandbox $sandbox, ?array $paths = null): array
    {
        return $this->call($sandbox, ['op' => 'stage', 'paths' => $paths]);
    }

    /**
     * Unstage the staged changes to $paths, or every one.
     *
     * @param  list<string>|null  $paths
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function unstage(Sandbox $sandbox, ?array $paths = null): array
    {
        return $this->call($sandbox, ['op' => 'unstage', 'paths' => $paths]);
    }

    /**
     * Stage (or, with $staged, unstage) one hunk or some lines of an edited file, whose diff was the one hashed.
     *
     * @param  list<int>|null  $lines  indexes of the patch's lines
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function stageLines(Sandbox $sandbox, string $path, string $hash, bool $staged, ?int $hunk, ?array $lines): array
    {
        return $this->call($sandbox, ['op' => 'stage_lines', 'path' => $path, 'hash' => $hash, 'staged' => $staged, 'hunk' => $hunk, 'lines' => $lines]);
    }

    /**
     * Undo the last commit ($sha, the one that was shown), bringing its changes back as uncommitted.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function undoCommit(Sandbox $sandbox, string $sha): array
    {
        return $this->call($sandbox, ['op' => 'undo_commit', 'sha' => $sha]);
    }

    /**
     * Put one hunk of an edited file back as it's staged (or as it was in the last commit).
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function discardHunk(Sandbox $sandbox, string $path, string $hash, int $hunk): array
    {
        return $this->call($sandbox, ['op' => 'discard_hunk', 'path' => $path, 'hash' => $hash, 'hunk' => $hunk]);
    }

    /**
     * One change's patch, its staged part or its unstaged part (a new file's is all added lines, a new folder lists
     * its files instead).
     *
     * @return array{path: string, staged: bool, patch: string, hash: ?string, truncated: bool, binary: bool, files: list<string>|null}
     *
     * @throws SandboxException|GitException
     */
    public function changeDiff(Sandbox $sandbox, string $path, bool $staged = false): array
    {
        return $this->call($sandbox, ['op' => 'change_diff', 'path' => $path, 'staged' => $staged]);
    }

    /**
     * What the current branch has that $base doesn't: its commits, newest first, and the diff, cut short.
     *
     * @return array{base: string, commits: list<array{sha: string, subject: string, author: string, email: string, date: string, agent: bool}>, more: bool, patch: string, truncated: bool}
     *
     * @throws SandboxException|GitException
     */
    public function compare(Sandbox $sandbox, string $base): array
    {
        return $this->call($sandbox, ['op' => 'compare', 'base' => $base]);
    }

    /**
     * The commits no remote has yet (newest first) and the diff they make together, cut short.
     *
     * @return array{commits: list<array{sha: string, subject: string, author: string, email: string, date: string, agent: bool}>, patch: string, truncated: bool}
     *
     * @throws SandboxException|GitException
     */
    public function combinePreview(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'combine_preview']);
    }

    /**
     * Combine the commits no remote has yet into one, as the user.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function combine(Sandbox $sandbox, string $message, User $user): array
    {
        return $this->call($sandbox, ['op' => 'combine', 'message' => $message, ...$this->author($user)]);
    }

    /**
     * What committing would commit (what's staged, or every change): the tracked files' diff, cut short, and the
     * new files' names.
     *
     * @return array{patch: string, new_files: list<string>, truncated: bool}
     *
     * @throws SandboxException|GitException
     */
    public function changesDiff(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'changes_diff']);
    }

    /**
     * Throw away unstaged changes to $paths, or all of them (staged changes stay).
     *
     * @param  list<string>|null  $paths
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function discard(Sandbox $sandbox, ?array $paths = null): array
    {
        return $this->call($sandbox, ['op' => 'discard', 'paths' => $paths]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function switch(Sandbox $sandbox, string $branch, bool $create = false): array
    {
        $status = $this->call($sandbox, ['op' => 'switch', 'branch' => $branch, 'create' => $create]);
        $this->restartApp($sandbox);

        return $status;
    }

    /**
     * Put every file back as it was at a commit, as a new commit (uncommitted changes are committed first).
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function restore(Sandbox $sandbox, string $sha, User $user): array
    {
        $status = $this->call($sandbox, ['op' => 'restore', 'sha' => $sha, ...$this->author($user)]);
        $this->restartApp($sandbox);

        return $status;
    }

    /**
     * The agent's private checkpoints, newest first, a page at a time (SCM-002).
     *
     * @return array{checkpoints: list<array{sha: string, subject: string, date: string, kind: string, files: int, additions: int, deletions: int, restorable_before: bool}>, more: bool}
     *
     * @throws SandboxException|GitException
     */
    public function checkpoints(Sandbox $sandbox, int $offset = 0): array
    {
        try {
            return $this->call($sandbox, ['op' => 'checkpoints', 'offset' => $offset]);
        } catch (SandboxException) {
            // A Git tool from before checkpoints (it's updated before the agent's next run): none to show yet.
            return ['checkpoints' => [], 'more' => false];
        }
    }

    /**
     * Put the files back as they were at a checkpoint, or just before it, as uncommitted changes.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function restoreCheckpoint(Sandbox $sandbox, string $sha, bool $before): array
    {
        $status = $this->call($sandbox, ['op' => 'restore_checkpoint', 'sha' => $sha, 'before' => $before]);
        $this->restartApp($sandbox);

        return $status;
    }

    /**
     * Record the commit the platform pushed for a branch.
     *
     * @throws SandboxException|GitException
     */
    public function pushed(Sandbox $sandbox, string $branch, string $sha): void
    {
        $this->call($sandbox, ['op' => 'pushed', 'branch' => $branch, 'sha' => $sha]);
    }

    /**
     * Fast-forward a branch to what the platform fetched, from a bundle already copied into the sandbox.
     *
     * @throws SandboxException|GitException
     */
    public function pulled(Sandbox $sandbox, string $branch, string $bundle): void
    {
        $this->call($sandbox, ['op' => 'pulled', 'branch' => $branch, 'bundle' => $bundle]);
        $this->restartApp($sandbox);
    }

    /**
     * @return array{name: string, email: string}
     */
    protected function author(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email];
    }

    /**
     * The files changed under the app's dev server; start it again.
     *
     * @throws SandboxException
     */
    protected function restartApp(Sandbox $sandbox): void
    {
        $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws SandboxException|GitException
     */
    protected function call(Sandbox $sandbox, array $request): mixed
    {
        $result = $this->provider->exec($sandbox->external_id, ['php', self::SCRIPT], [
            'APP_GIT_REQUEST' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $response = json_decode(trim($result->output), true);

        if (! is_array($response) || ! isset($response['ok']) || ($response['error'] ?? null) === 'Unknown operation.') {
            throw new SandboxException(__("This sandbox's Git tool is missing or out of date. Open the project again to update its sandbox."));
        }

        if ($response['ok'] !== true) {
            throw new GitException((string) ($response['error'] ?? __('The Git request failed.')));
        }

        return $response['data'];
    }
}
