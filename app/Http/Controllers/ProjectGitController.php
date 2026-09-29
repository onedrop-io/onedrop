<?php

namespace App\Http\Controllers;

use App\Enums\GitSyncStatus;
use App\Enums\SandboxStatus;
use App\Jobs\BackupProject;
use App\Jobs\SyncGitRemote;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\GitException;
use App\Sandbox\GitHubApp;
use App\Sandbox\GitRemote;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceGit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProjectGitController extends Controller
{
    /**
     * The branch, uncommitted changes, recent commits, and the remote (never its token).
     */
    public function index(Request $request, Project $project, WorkspaceGit $git, GitHubApp $github): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => [
            'status' => $git->status($sandbox),
            ...$git->history($sandbox),
            ...self::remote($project),
            'github' => $this->githubApp($project, $github, $request),
        ]);
    }

    /**
     * A page of the history, optionally searched by message, author or commit id.
     */
    public function log(Request $request, Project $project, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $git->history($sandbox, $validated['query'] ?? null, (int) ($validated['offset'] ?? 0)));
    }

    /**
     * One commit's full message, author, date and changed files.
     */
    public function show(Project $project, string $sha, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['commit' => $git->show($sandbox, $sha)]);
    }

    /**
     * The diff a commit made to one of its files.
     */
    public function diff(Request $request, Project $project, string $sha, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('view', $project);

        $path = $request->validate(['path' => ['required', 'string', 'max:1000']])['path'];

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $git->diff($sandbox, $sha, $path));
    }

    /**
     * Commit every change, as the signed-in user.
     */
    public function commit(Request $request, Project $project, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('update', $project);

        $message = $request->validate(['message' => ['required', 'string', 'max:5000']])['message'];

        return $this->changing($project, function (Sandbox $sandbox) use ($git, $message, $request, $project) {
            $status = $git->commit($sandbox, $message, $request->user());
            BackupProject::dispatch($project);

            return ['status' => $status, 'commits' => $git->log($sandbox)];
        });
    }

    /**
     * Throw away uncommitted changes to one file, or all of them.
     */
    public function discard(Request $request, Project $project, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('update', $project);

        $path = $request->validate(['path' => ['nullable', 'string', 'max:1000']])['path'] ?? null;

        return $this->changing($project, fn (Sandbox $sandbox) => ['status' => $git->discard($sandbox, $path)]);
    }

    /**
     * Switch to a branch, or create one from the current commit.
     */
    public function switch(Request $request, Project $project, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'branch' => ['required', 'string', 'max:100'],
            'create' => ['sometimes', 'boolean'],
        ]);

        return $this->changing($project, fn (Sandbox $sandbox) => [
            'status' => $git->switch($sandbox, $validated['branch'], (bool) ($validated['create'] ?? false)),
            'commits' => $git->log($sandbox),
        ]);
    }

    /**
     * Put the app back as it was at an earlier commit, as a new commit.
     */
    public function restore(Request $request, Project $project, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('update', $project);

        $sha = $request->validate(['sha' => ['required', 'string', 'regex:/^[0-9a-f]{7,40}$/']])['sha'];

        return $this->changing($project, function (Sandbox $sandbox) use ($git, $sha, $request, $project) {
            $status = $git->restore($sandbox, $sha, $request->user());
            BackupProject::dispatch($project);

            return ['status' => $status, 'commits' => $git->log($sandbox)];
        });
    }

    /**
     * Connect an existing repository by its HTTPS URL and a token that can push to it.
     */
    public function connect(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url', $this->allowedRemote(...)],
            'username' => ['nullable', 'string', 'max:100'],
            'token' => ['required', 'string', 'max:500'],
        ]);

        $project->update([
            'git_remote_url' => $validated['url'],
            'git_remote_username' => $validated['username'] ?? null,
            'git_remote_token' => $validated['token'],
            'github_installation_id' => null,
            'git_sync_status' => null,
            'git_sync_error' => null,
            'git_synced_at' => null,
        ]);

        return response()->json(self::remote($project));
    }

    /**
     * Make a new repository on GitHub with the user's token, connect it, and push to it.
     */
    public function github(Request $request, Project $project, GitRemote $remote): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'token' => ['required', 'string', 'max:500'],
            'private' => ['sometimes', 'boolean'],
        ]);

        try {
            $url = $remote->createGitHubRepository($validated['token'], $validated['name'], (bool) ($validated['private'] ?? true));
        } catch (GitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $project->update([
            'git_remote_url' => $url,
            'git_remote_username' => null,
            'git_remote_token' => $validated['token'],
            'github_installation_id' => null,
            'git_sync_status' => GitSyncStatus::Pushing,
            'git_sync_error' => null,
            'git_synced_at' => null,
        ]);

        SyncGitRemote::dispatch($project, GitSyncStatus::Pushing);

        return response()->json(self::remote($project));
    }

    /**
     * Forget the remote and its token (the repository itself is untouched).
     */
    public function disconnect(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $project->update([
            'git_remote_url' => null,
            'git_remote_username' => null,
            'git_remote_token' => null,
            'github_installation_id' => null,
            'git_sync_status' => null,
            'git_sync_error' => null,
            'git_synced_at' => null,
        ]);

        return response()->json(self::remote($project));
    }

    public function push(Project $project): JsonResponse
    {
        return $this->sync($project, GitSyncStatus::Pushing);
    }

    public function pull(Project $project): JsonResponse
    {
        return $this->sync($project, GitSyncStatus::Pulling);
    }

    protected function sync(Project $project, GitSyncStatus $direction): JsonResponse
    {
        Gate::authorize('update', $project);

        if ($project->git_remote_url === null) {
            return response()->json(['message' => __('Connect a remote repository first.')], 422);
        }

        if (in_array($project->git_sync_status, [GitSyncStatus::Pushing, GitSyncStatus::Pulling], true)) {
            return response()->json(['message' => __('A push or pull is already running.')], 409);
        }

        if ($direction === GitSyncStatus::Pulling && $project->busyIn($project->sandbox)) {
            return response()->json(['message' => __('Wait for the agent to finish, then pull.')], 409);
        }

        $project->update(['git_sync_status' => $direction, 'git_sync_error' => null]);
        SyncGitRemote::dispatch($project, $direction);

        return response()->json(self::remote($project));
    }

    /**
     * The remote (never its token) and the last backup, as the Git panel shows them.
     *
     * @return array{remote: array{url: string, host: ?string, username: ?string, github_app: bool, sync_status: ?string, sync_error: ?string, synced_at: ?string}|null, backed_up_at: ?string}
     */
    public static function remote(Project $project): array
    {
        return [
            'remote' => $project->git_remote_url === null ? null : [
                'url' => $project->git_remote_url,
                'host' => parse_url($project->git_remote_url, PHP_URL_HOST) ?: null,
                'username' => $project->git_remote_username,
                'github_app' => $project->github_installation_id !== null,
                'sync_status' => $project->git_sync_status?->value,
                'sync_error' => $project->git_sync_error,
                'synced_at' => $project->git_synced_at?->toIso8601String(),
            ],
            'backed_up_at' => $project->backed_up_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the GitHub App is set up (and, for admins, what's wrong with it), whether the user is signed in
     * through it, and their installations.
     *
     * @return array{configured: bool, suggested_name?: string, problems: list<string>, settings_url: ?string, signed_in: bool, login: ?string, connect_url: ?string, reconnect_url: ?string, installations: list<array{id: int, account: string, type: string, avatar_url: ?string, selection: ?string, can_create: bool, manage_url: string}>}
     */
    protected function githubApp(Project $project, GitHubApp $github, Request $request): array
    {
        $admin = (bool) $request->user()?->is_admin;
        $problems = $admin ? $github->problems() : [];

        if (! $github->configured()) {
            return ['configured' => false, 'problems' => $problems, 'settings_url' => null, 'signed_in' => false, 'login' => null, 'connect_url' => null, 'reconnect_url' => null, 'installations' => []];
        }

        $canCreate = $github->canCreateRepositories();
        $signedIn = $github->userToken($project->user) !== null;

        if ($admin && ! $canCreate) {
            $problems[] = __('To create organization repositories from OneDrop, give the GitHub App the Administration: Read and write repository permission.');
        }

        return [
            'configured' => true,
            'suggested_name' => Str::slug($project->name) ?: 'my-app',
            'problems' => $problems,
            'settings_url' => $admin ? $github->settingsUrl() : null,
            'signed_in' => $signedIn,
            'login' => $signedIn ? $project->user->githubAuthorization?->github_login : null,
            'connect_url' => route('projects.git.github-app.install', $project),
            'reconnect_url' => route('projects.git.github-app.install', [$project, 'reconnect' => 1]),
            'installations' => $signedIn ? $project->user->githubInstallations()->orderByRaw("account_type = 'Organization'")->orderBy('account_login')->get()->map(fn (GitHubInstallation $installation) => [
                'id' => $installation->installation_id,
                'account' => $installation->account_login,
                'type' => $installation->account_type,
                'avatar_url' => $installation->account_avatar_url,
                'selection' => $installation->repository_selection,
                'can_create' => $canCreate && $installation->account_type === 'Organization',
                'manage_url' => $github->manageUrl($installation),
            ])->all() : [],
        ];
    }

    protected function allowedRemote(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ($problem = GitRemote::problemWith($value)) !== null) {
            $fail($problem);
        }
    }

    /**
     * Like fromSandbox, but not while the agent is changing the same files.
     *
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function changing(Project $project, callable $call): JsonResponse
    {
        if ($project->busyIn($project->sandbox)) {
            return response()->json(['message' => __('Wait for the agent to finish first.')], 409);
        }

        return $this->fromSandbox($project, $call);
    }

    /**
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($call($sandbox));
        } catch (GitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => Str::limit($e->getMessage(), 500)], 502);
        }
    }
}
