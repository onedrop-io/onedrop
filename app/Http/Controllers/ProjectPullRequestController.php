<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Jobs\ForkTaskSandbox;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\GitException;
use App\Sandbox\GitHubPulls;
use App\Sandbox\PullRequestFixes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Tools → Pull requests (GIT-013): the project's GitHub pull requests, read on the platform; checking one out as a
 * task (GIT-014), and sending its failed checks to that task's agent (GIT-015).
 */
class ProjectPullRequestController extends Controller
{
    /**
     * Open (or closed) pull requests, and which are checked out as tasks.
     */
    public function index(Request $request, Project $project, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('view', $project);

        if (! GitHubPulls::available($project)) {
            return response()->json(['available' => false, 'repository' => null, 'pulls' => [], 'tasks' => (object) []]);
        }

        return $this->fromGitHub(fn () => response()->json([
            'available' => true,
            'repository' => GitHubPulls::repository($project),
            'pulls' => $pulls->list($project, closed: $request->boolean('closed')),
            'tasks' => (object) $project->tasks()->whereNotNull('pull_request_number')->pluck('id', 'pull_request_number')->all(),
        ]));
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
            $task = $this->taskFor($project, $number);
            $this->remember($task, $pull['head_sha'], $checks);

            return response()->json([
                'pull' => $pull,
                'conversation' => $pulls->conversation($project, $number),
                'checks' => $checks,
                'checks_state' => GitHubPulls::summarize($checks),
                'task' => $task ? $this->describeTask($project, $task) : null,
                'check_out_problem' => $task ? null : $this->checkOutProblem($project, $pull),
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
            $this->remember($this->taskFor($project, $number), $sha, $checks);

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
    public function checkOut(Project $project, int $number, GitHubPulls $pulls): JsonResponse
    {
        Gate::authorize('update', $project);

        if ($task = $this->taskFor($project, $number)) {
            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        }

        return $this->fromGitHub(function () use ($project, $number, $pulls) {
            $pull = $pulls->find($project, $number);

            if (($problem = $this->checkOutProblem($project, $pull)) !== null) {
                return response()->json(['message' => $problem], 422);
            }

            $task = $this->newTask($project, $pull);
            // Working on its copy: messages sent meanwhile wait for it (see ForkTaskSandbox).
            $task->update(['status' => ProjectStatus::Working, 'sync_status' => TaskSyncStatus::Forking]);
            $task->sandbox()->updateOrCreate([], ['provider' => $project->sandboxProvider(), 'status' => SandboxStatus::Creating, 'external_id' => null, 'error' => null]);
            ForkTaskSandbox::dispatch($task, checkOutOnly: true);

            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        });
    }

    /**
     * Send the failed checks to the pull request's task's agent, checking it out first when it has no task.
     */
    public function fix(Project $project, int $number, GitHubPulls $pulls, PullRequestFixes $fixes): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromGitHub(function () use ($project, $number, $pulls, $fixes) {
            $pull = $pulls->find($project, $number);
            $checks = $pulls->checks($project, $pull['head_sha'], fresh: true);

            if (GitHubPulls::failed($checks) === []) {
                return response()->json(['message' => __('No checks have failed on its newest commit.')], 422);
            }

            $task = $this->taskFor($project, $number);

            if ($task === null) {
                if (($problem = $this->checkOutProblem($project, $pull)) !== null) {
                    return response()->json(['message' => $problem], 422);
                }

                // Its first message makes the copy on the pull request's branch, then the agent runs (TASK-003).
                $task = $this->newTask($project, $pull);
            }

            $fixes->send($task, $checks, $pull['head_sha']);

            return response()->json(['url' => route('projects.tasks.show', [$project, $task])]);
        });
    }

    /**
     * Why the pull request can't be checked out as a task, or null when it can.
     *
     * @param  array<string, mixed>  $pull
     */
    protected function checkOutProblem(Project $project, array $pull): ?string
    {
        return match (true) {
            ! Task::getsCopies() => __('Checking out needs tasks to get their own copy of the app, which is turned off here (SANDBOX_TASK_COPIES).'),
            ! in_array($pull['state'], ['open', 'draft'], true) => __('Only open pull requests can be checked out.'),
            $project->taskCopyLimitReached() => TaskController::copyLimitError($project)->getMessage(),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $pull
     */
    protected function newTask(Project $project, array $pull): Task
    {
        return $project->tasks()->create([
            'title' => Str::limit("#{$pull['number']} {$pull['title']}", 80, ''),
            // A pull request is there to be reviewed; the agent's first turn moves it to In progress (TASK-002).
            'stage' => TaskStage::Review,
            'position' => $project->nextTaskPosition(TaskStage::Review),
            'pull_request_number' => $pull['number'],
            'pull_request_branch' => $pull['head'],
            'pull_request_base' => $pull['base'],
            'pull_request_fork' => $pull['fork'],
        ]);
    }

    protected function taskFor(Project $project, int $number): ?Task
    {
        return $project->tasks()->where('pull_request_number', $number)->first();
    }

    /**
     * @return array{id: int, title: string, url: string, status: mixed, checks: ?string}
     */
    protected function describeTask(Project $project, Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'url' => route('projects.tasks.show', [$project, $task]),
            'status' => $task->status,
            'checks' => $task->pull_request_checks,
        ];
    }

    /**
     * Keep the task's checks as last seen when they're for its newest commit, so the board and its page agree with GitHub.
     *
     * @param  list<array<string, mixed>>  $checks
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
