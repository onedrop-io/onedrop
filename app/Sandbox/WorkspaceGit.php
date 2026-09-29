<?php

namespace App\Sandbox;

use App\Models\Sandbox;
use App\Models\User;

/**
 * The app's git repository (branch, changes, history, commits, restores), reached through docker/sandbox/git.php
 * inside the sandbox. Remotes are handled by GitRemote on the platform, so their credentials never enter the sandbox.
 */
class WorkspaceGit
{
    public const SCRIPT = '/opt/zap/git.php';

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * @return array{initialized: bool, branch: ?string, branches: list<string>, head: ?string, changes: list<array{path: string, status: string}>, more_changes: bool, tracking: array{ahead: int, behind: int}|null, state: ?string}
     *
     * @throws SandboxException|GitException
     */
    public function status(Sandbox $sandbox): array
    {
        return $this->call($sandbox, ['op' => 'status']);
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
     * Commit every change (dependencies and secrets excluded) as the user.
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
     * Throw away uncommitted changes to one path, or all of them.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException|GitException
     */
    public function discard(Sandbox $sandbox, ?string $path = null): array
    {
        return $this->call($sandbox, ['op' => 'discard', 'path' => $path]);
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
        $this->provider->exec($sandbox->external_id, ['/opt/zap/restart']);
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
