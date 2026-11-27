<?php

namespace App\Http\Controllers;

use App\Jobs\CatchUpPullRequests;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Gateway;
use App\Sandbox\GitException;
use App\Sandbox\GitHubPulls;
use App\Sandbox\PullRequestCheckouts;
use App\Sandbox\PullRequestFixes;
use App\Sandbox\PullRequestPreviews;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tools → Pull requests (GIT-013): the project's GitHub pull requests, read on the platform; checking one out as a
 * task (GIT-014), and sending its failed checks to that task's agent (GIT-015).
 */
class ProjectPullRequestController extends Controller
{
    /**
     * The Pull requests page (GIT-022): the same list and pull request pages as Tools → Pull requests, full width.
     */
    public function page(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/pull-requests', [
            'project' => $project->only('id', 'name'),
        ]);
    }

    /**
     * Open (or closed) pull requests, and which are checked out as tasks. Listing the open ones also brings the tasks
     * up to date: opened pull requests are linked, merged or closed ones' tasks move to Done (GIT-023, GIT-024).
     */
    public function index(Request $request, Project $project, GitHubPulls $pulls, PullRequestPreviews $previews, Gateway $gateway): JsonResponse
    {
        Gate::authorize('view', $project);

        if (! GitHubPulls::available($project)) {
            return response()->json(['available' => false, 'repository' => null, 'pulls' => [], 'tasks' => (object) [], 'previews' => (object) [], 'preview_pull_requests' => $project->pullRequestPreviews()]);
        }

        return $this->fromGitHub(function () use ($request, $project, $pulls, $previews, $gateway) {
            $list = $pulls->list($project, closed: $request->boolean('closed'));

            // Catch up on what no webhook said: new pull requests checked out, commits brought in, closed ones settled (GIT-026).
            if (! $request->boolean('closed')) {
                $previews->catchUp($project, $list);
            }

            $tasks = $project->tasks()->whereNotNull('pull_request_number')->get();

            return response()->json([
                'available' => true,
                'repository' => GitHubPulls::repository($project),
                'pulls' => $list,
                'tasks' => (object) $tasks->pluck('id', 'pull_request_number')->all(),
                // Each preview's link, for those that have one (GIT-027).
                'previews' => (object) $tasks->mapWithKeys(fn (Task $task) => [$task->pull_request_number => $gateway->pullRequestLink($task)])->filter()->all(),
                'preview_pull_requests' => $project->pullRequestPreviews(),
            ]);
        });
    }

    /**
     * Preview no pull requests, only new ones, or every one (GIT-026). Every one checks out the open ones at once; new
     * ones counts from now, unless it was already counting.
     */
    public function settings(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $choice = $request->validate(['preview_pull_requests' => ['required', Rule::in(['off', 'new', 'all'])]])['preview_pull_requests'];

        $project->update([
            'preview_pull_requests' => $choice !== 'off',
            'preview_pull_requests_since' => $choice === 'new' ? ($project->pullRequestPreviews() === 'new' ? $project->preview_pull_requests_since : now()) : null,
        ]);

        if ($choice === 'all' && GitHubPulls::available($project)) {
            CatchUpPullRequests::dispatch($project);
        }

        return response()->json(['preview_pull_requests' => $project->pullRequestPreviews()]);
    }

    /**
     * One pull request with its conversation and checks, its task, and why it can't be checked out (if it can't).
     */
    public function show(Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromGitHub(function () use ($project, $number, $pulls) {
            $pull = $pulls->find($project, $number);
            $checks = $pulls->checks($project, $pull['head_sha']);
            $task = app(PullRequestCheckouts::class)->taskFor($project, $number);
            $this->remember($task, $pull['head_sha'], $checks);

            return response()->json([
                'pull' => $pull,
                'conversation' => $pulls->conversation($project, $number),
                'checks' => $checks,
                'checks_state' => GitHubPulls::summarize($checks),
                'task' => $task ? $this->describeTask($project, $task) : null,
                'check_out_problem' => $task ? null : app(PullRequestCheckouts::class)->problem($project, $pull),
            ]);
        });
    }

    public function commits(Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromGitHub(fn () => response()->json(['commits' => $pulls->commits($project, $number)]));
    }

    public function files(Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromGitHub(fn () => response()->json(['files' => $pulls->files($project, $number)]));
    }

    /**
     * The checks on the pull request's newest commit, for refreshing while they run.
     */
    public function checks(Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromGitHub(function () use ($project, $number, $pulls) {
            $sha = $pulls->find($project, $number)['head_sha'];
            $checks = $pulls->checks($project, $sha);
            $this->remember(app(PullRequestCheckouts::class)->taskFor($project, $number), $sha, $checks);

            return response()->json(['sha' => $sha, 'checks' => $checks, 'checks_state' => GitHubPulls::summarize($checks)]);
        });
    }

    /**
     * The end of one failed check's log (or another CI's summary).
     */
    public function log(Project $project, int $number, string $check, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromGitHub(function () use ($project, $number, $check, $pulls) {
            $found = collect($pulls->checks($project, $pulls->find($project, $number)['head_sha']))->firstWhere('id', $check);
            abort_if($found === null, 404);

            return response()->json(['log' => $pulls->log($project, $found, GitHubPulls::LOGS_CHARACTERS)]);
        });
    }

    /**
     * Check the pull request out as a task: its own copy of the app, on the pull request's branch, with no agent run.
     * A pull request already checked out goes to its task.
     */
    public function checkOut(Project $project, int $number, GitHubPulls $pulls, PullRequestCheckouts $checkouts): JsonResponse
    {
        Gate::authorize('update', $project);

        if ($task = $checkouts->taskFor($project, $number)) {
            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        }

        return $this->fromGitHub(function () use ($project, $number, $pulls, $checkouts) {
            $pull = $pulls->find($project, $number);

            if (($problem = $checkouts->problem($project, $pull)) !== null) {
                return response()->json(['message' => $problem], 422);
            }

            $task = $checkouts->checkOut($project, $pull);

            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        });
    }

    /**
     * Send the failed checks to the pull request's task's agent, checking it out first when it has no task.
     */
    public function fix(Project $project, int $number, GitHubPulls $pulls, PullRequestFixes $fixes, PullRequestCheckouts $checkouts): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromGitHub(function () use ($project, $number, $pulls, $fixes, $checkouts) {
            $pull = $pulls->find($project, $number);
            $checks = $pulls->checks($project, $pull['head_sha'], fresh: true);

            if (GitHubPulls::failed($checks) === []) {
                return response()->json(['message' => __('No checks have failed on its newest commit.')], 422);
            }

            $task = $checkouts->taskFor($project, $number);

            if ($task === null) {
                if (($problem = $checkouts->problem($project, $pull)) !== null) {
                    return response()->json(['message' => $problem], 422);
                }

                // Its first message makes the copy on the pull request's branch, then the agent runs (TASK-003).
                $task = $checkouts->newTask($project, $pull);
            }

            $fixes->send($task, $checks, $pull['head_sha']);

            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        });
    }

    /**
     * @return array{id: int, title: string, url: string, status: mixed, checks: ?string, preview_url: ?string, preview_error: ?string}
     */
    protected function describeTask(Project $project, Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'url' => route('projects.tasks.show', [$project, $task]),
            'status' => $task->status,
            'checks' => $task->pull_request_checks,
            // Its preview link (GIT-027), and why GitHub refused its Deployment or comment (GIT-028).
            'preview_url' => app(Gateway::class)->pullRequestLink($task),
            'preview_error' => $task->preview_error,
        ];
    }

    /**
     * Keep the task's checks as last seen when they're for its newest commit, so the board and its page agree with GitHub.
     *
     * @param  list<array{state: string}>  $checks
     */
    protected function remember(?Task $task, string $sha, array $checks): void
    {
        if ($task !== null && $task->pull_request_head_sha === $sha && ($state = GitHubPulls::summarize($checks)) !== $task->pull_request_checks) {
            $task->update(['pull_request_checks' => $state]);
        }
    }

    /**
     * @param  Closure(): JsonResponse  $callback
     */
    protected function fromGitHub(Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (GitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
