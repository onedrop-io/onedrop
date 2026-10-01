<?php

namespace App\Http\Controllers;

use App\Enums\GitSyncStatus;
use App\Enums\SandboxStatus;
use App\Jobs\SyncGitRemote;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\GitHubApp;
use App\Sandbox\GitHubSignInNeeded;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceGit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Connecting repositories through the platform's GitHub App (Tools → Git → Connect to GitHub): installing it and
 * signing in through it, then creating a repository or picking one (and a branch) that the user and the app can both reach.
 */
class GitHubAppController extends Controller
{
    /** GitHub's rules for repository names (and a length we accept). */
    protected const NAME_RULES = ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', 'not_in:.,..'];

    /**
     * Send the user to GitHub to install the app (or, with ?reconnect, just to sign in through it again),
     * then back to the project's Git section with the dialog open.
     */
    public function install(Request $request, Project $project, GitHubApp $github): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($github->configured(), 404);

        $state = Str::random(40);
        $request->session()->put('github_app', ['state' => $state, 'project' => $project->id]);

        return redirect()->away($request->boolean('reconnect') ? $github->authorizeUrl($state) : $github->installUrl($state));
    }

    /**
     * The same from the new-project page, to import a private repository (PRJ-009); GitHub sends them back there.
     */
    public function installForImport(Request $request, GitHubApp $github): RedirectResponse
    {
        abort_unless($github->configured(), 404);

        $state = Str::random(40);
        $request->session()->put('github_app', ['state' => $state, 'project' => null]);

        return redirect()->away($request->boolean('reconnect') ? $github->authorizeUrl($state) : $github->installUrl($state));
    }

    /**
     * Every repository the user and the app can both reach, across their installations, most recently pushed
     * first: the new-project page's picker for importing one (PRJ-009).
     */
    public function importable(Request $request, GitHubApp $github): JsonResponse
    {
        abort_unless($github->configured(), 404);

        $user = $request->user();

        return $this->fromGitHub(function () use ($github, $user) {
            $repositories = $user->githubInstallations()->get()
                ->flatMap(fn (GitHubInstallation $installation) => $github->repositories($user, $installation->installation_id))
                ->reject(fn (array $repository) => $repository['empty'])
                ->unique('full_name')
                ->sortByDesc('pushed_at')
                ->map(fn (array $repository) => Arr::only($repository, ['full_name', 'private', 'html_url', 'pushed_at']))
                ->values()
                ->all();

            return ['repositories' => $repositories];
        });
    }

    /**
     * Whether a request is GitHub coming back from a Tools → Git connection this session started.
     */
    public static function isReturning(Request $request): bool
    {
        $pending = $request->session()->get('github_app');

        return is_array($pending) && is_string($request->query('state')) && hash_equals((string) $pending['state'], $request->query('state'));
    }

    /**
     * GitHub's return from installing (its Setup URL) and from signing in through the app (its Callback URL).
     * Signing in tells us which installations the user can reach; only those are remembered for them.
     */
    public function callback(Request $request, GitHubApp $github): RedirectResponse
    {
        abort_unless($github->configured(), 404);

        $pending = $request->session()->get('github_app');
        $projectId = is_array($pending) ? ($pending['project'] ?? null) : null;
        $project = is_int($projectId) ? Project::find($projectId) : null;
        // Started from the new-project page, to import a repository (PRJ-009).
        $forImport = is_array($pending) && array_key_exists('project', $pending) && $pending['project'] === null;

        if (! is_array($pending) || ! hash_equals((string) $pending['state'], (string) $request->query('state')) || (! $forImport && (! $project || $request->user()->cannot('update', $project)))) {
            abort(403, __('This GitHub connection was started from another session. Start again from Tools → Git.'));
        }

        if (is_string($request->query('error'))) {
            return $this->backToGit($request, $project, __('GitHub sign-in was cancelled.'));
        }

        // Installed without signing in through the app (or an org owner must approve it first): sign in now.
        if (! is_string($request->query('code'))) {
            if ($request->query('setup_action') === 'request') {
                return $this->backToGit($request, $project, __('An owner of that organization has to approve the app on GitHub first.'));
            }

            return redirect()->away($github->authorizeUrl($pending['state']));
        }

        $user = $request->user();

        try {
            $installations = $github->authorize($user, $request->query('code'));
        } catch (GitException $e) {
            return $this->backToGit($request, $project, $e->getMessage());
        }

        foreach ($installations as $installation) {
            $user->githubInstallations()->updateOrCreate(['installation_id' => $installation['installation_id']], $installation);
        }

        // Installations the user can no longer reach (uninstalled, or they left the organization) go.
        $user->githubInstallations()->whereNotIn('installation_id', array_column($installations, 'installation_id'))->delete();

        return $this->backToGit($request, $project);
    }

    /**
     * The repositories of one installation that the user can reach too, most recently pushed first.
     */
    public function repositories(Request $request, Project $project, GitHubApp $github): JsonResponse
    {
        Gate::authorize('update', $project);

        $installation = $this->installation($request, $project);

        return $this->fromGitHub(fn () => ['repositories' => $github->repositories($project->user, $installation->installation_id)]);
    }

    /**
     * Whether a repository name is free on an installation's account.
     */
    public function availability(Request $request, Project $project, GitHubApp $github): JsonResponse
    {
        Gate::authorize('update', $project);

        $installation = $this->installation($request, $project);
        $name = $request->validate(['name' => self::NAME_RULES])['name'];

        return $this->fromGitHub(fn () => ['available' => $github->available($project->user, $installation->account_login, $name)]);
    }

    /**
     * A repository's branches.
     */
    public function branches(Request $request, Project $project, GitHubApp $github): JsonResponse
    {
        Gate::authorize('update', $project);

        $this->installation($request, $project);
        $repository = $this->repositoryName($request);

        return $this->fromGitHub(fn () => ['branches' => $github->branches($project->user, $repository)]);
    }

    /**
     * Create a new repository in an organization (the app can't create them on personal accounts), connect it and push.
     */
    public function create(Request $request, Project $project, GitHubApp $github): JsonResponse
    {
        Gate::authorize('update', $project);

        $installation = $this->installation($request, $project);
        $validated = $request->validate(['name' => self::NAME_RULES, 'private' => ['sometimes', 'boolean']]);

        if ($installation->account_type !== 'Organization' || ! $github->canCreateRepositories()) {
            return response()->json(['message' => __('OneDrop can\'t create repositories on :owner. Create it on GitHub, then connect it.', ['owner' => $installation->account_login])], 422);
        }

        return $this->fromGitHub(function () use ($github, $installation, $validated, $project) {
            $repository = $github->createOrganizationRepository($installation, $validated['name'], (bool) ($validated['private'] ?? true));
            $this->connectTo($project, $installation, $repository['clone_url'], GitSyncStatus::Pushing);

            return ProjectGitController::remote($project);
        });
    }

    /**
     * Connect an existing repository the user and the app can both reach. A project with no commits yet
     * imports the chosen branch; otherwise a repository with nothing in it is pushed to.
     */
    public function connect(Request $request, Project $project, GitHubApp $github, WorkspaceGit $git): JsonResponse
    {
        Gate::authorize('update', $project);

        $installation = $this->installation($request, $project);
        $fullName = $this->repositoryName($request);
        $branch = $request->validate(['branch' => ['nullable', 'string', 'max:100']])['branch'] ?? null;

        return $this->fromGitHub(function () use ($github, $git, $installation, $fullName, $branch, $project) {
            // As the user sees it: only a repository they can reach.
            $repository = $github->find($project->user, $fullName);
            $hasNoCommits = $this->hasNoCommits($project, $git);

            $this->connectTo($project, $installation, $repository['clone_url'], match (true) {
                $hasNoCommits && ! $repository['empty'] => GitSyncStatus::Pulling,
                ! $hasNoCommits && $repository['empty'] => GitSyncStatus::Pushing,
                default => null,
            }, $branch ?? $repository['default_branch']);

            return ProjectGitController::remote($project);
        });
    }

    /**
     * Point the project's remote at a GitHub repository (no token kept), then push or pull if asked.
     */
    protected function connectTo(Project $project, GitHubInstallation $installation, string $cloneUrl, ?GitSyncStatus $sync, ?string $branch = null): void
    {
        $project->update([
            'git_remote_url' => $cloneUrl,
            'git_remote_username' => null,
            'git_remote_token' => null,
            'github_installation_id' => $installation->installation_id,
            'git_sync_status' => $sync,
            'git_sync_error' => null,
            'git_synced_at' => null,
        ]);

        if ($sync !== null) {
            SyncGitRemote::dispatch($project, $sync, $sync === GitSyncStatus::Pulling ? $branch : null);
        }
    }

    /**
     * The request's installation, which must be one of the user's.
     */
    protected function installation(Request $request, Project $project): GitHubInstallation
    {
        $id = $request->validate(['installation_id' => ['required', 'integer']])['installation_id'];
        $installation = $project->user->githubInstallations()->where('installation_id', $id)->first();

        abort_if($installation === null, 403, __('That GitHub installation isn\'t one of yours. Connect GitHub again.'));

        return $installation;
    }

    protected function repositoryName(Request $request): string
    {
        return $request->validate(['repository' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/']])['repository'];
    }

    /**
     * @param  Closure(): array<string, mixed>  $call
     */
    protected function fromGitHub(Closure $call): JsonResponse
    {
        try {
            return response()->json($call());
        } catch (GitHubSignInNeeded $e) {
            return response()->json(['message' => $e->getMessage(), 'reconnect' => true], 401);
        } catch (GitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function hasNoCommits(Project $project, WorkspaceGit $git): bool
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return false;
        }

        try {
            return $git->status($sandbox)['head'] === null;
        } catch (GitException|SandboxException) {
            return false;
        }
    }

    protected function backToGit(Request $request, ?Project $project, ?string $error = null): RedirectResponse
    {
        $request->session()->forget('github_app');

        if ($project === null) {
            // Flashed, not in the URL: the dashboard redirects on to the organization's new-project page.
            return to_route('dashboard')->with('github_import', ['error' => $error]);
        }

        return redirect()->to(route('projects.show', $project).'?'.http_build_query(array_filter(['tool' => 'git', 'github' => 'connect', 'github_error' => $error])));
    }
}
