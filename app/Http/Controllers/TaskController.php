<?php

namespace App\Http\Controllers;

use App\Concerns\RendersWorkspace;
use App\Concerns\ValidatesAgentSelection;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskStart;
use App\Enums\TaskSyncStatus;
use App\Jobs\CheckTurnOutcome;
use App\Jobs\OpenTaskPullRequest;
use App\Jobs\RefreshPullRequestTasks;
use App\Jobs\SyncPullRequestTask;
use App\Jobs\SyncTask;
use App\Jobs\UpdatePullRequestPreview;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\PullRequestCheckouts;
use App\Sandbox\PullRequestFixes;
use App\Sandbox\PullRequestReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's tasks: each has its own agent and chat, and they run in parallel (TASK-001), shown on a board (TASK-002).
 */
class TaskController extends Controller
{
    use RendersWorkspace;
    use ValidatesAgentSelection;

    /** The branch names the sandbox's git tool accepts (BRANCH_PATTERN in docker/sandbox/git.php). */
    public const BRANCH_PATTERN = '/^(?!-)(?!.*\.\.)(?!.*\/\/)(?!.*@\{)[A-Za-z0-9._\/-]{1,100}(?<!\.lock)(?<![\/.])$/';

    /**
     * The project's board: every task, by column.
     */
    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        $tasks = $project->tasks()->get();
        // Pull requests merged or closed on GitHub move their tasks to Done (GIT-023), seen on the board's next poll or visit.
        RefreshPullRequestTasks::soon($project);

        return Inertia::render('projects/board', [
            'project' => $project->only('id', 'name', 'status'),
            'stages' => array_map(fn (TaskStage $stage) => ['value' => $stage->value, 'label' => $stage->label()], TaskStage::cases()),
            'tasks' => $tasks->map(fn (Task $task): array => [
                ...$task->only('id', 'title', 'description', 'stage', 'status'),
                'activity' => $task->isWorking() ? $task->currentActivity() : null,
                // Why its agent is waiting for the user after its last turn, and whether that's still being checked (PRJ-011).
                'waiting_for' => ! $task->isWorking() && $task->turn_outcome?->waiting() ? $task->turn_outcome->value : null,
                'checking' => CheckTurnOutcome::checking($task),
                'updated_at' => $task->updated_at?->toIso8601String(),
                // Checked out from a pull request (GIT-014): its number, its checks as last seen (GIT-015), merged or closed (GIT-023).
                'pull_request' => $task->isPullRequest() ? ['number' => $task->pull_request_number, 'checks' => $task->pull_request_checks, 'state' => $task->pull_request_state] : null,
            ]),
        ]);
    }

    /**
     * An empty chat: the first message starts a new task with a fresh agent.
     */
    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('update', $project);

        return $this->renderWorkspace($request, $project, $project, newTask: true);
    }

    /**
     * Start a task with a first message (it runs right away, alongside the project's other agents); check out where
     * it starts with no message (its copy is made and its app started, no agent; TASK-006); or, with only a title,
     * add a card to To do on the board without starting it.
     */
    public function store(Request $request, Project $project, AgentQueue $queue, PullRequestCheckouts $checkouts, ModelCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            // Checking out (TASK-006) names the task after where it starts.
            'title' => ['nullable', 'string', 'max:80', Rule::requiredIf(fn () => ! $request->boolean('check_out') && blank($request->input('content')) && ! $request->hasFile('attachments'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.Attachment::MAX_FILES],
            'attachments.*' => ['file', 'max:'.Attachment::MAX_KILOBYTES],
            // Where its copy of the app starts (TASK-005); without one, a fresh clone of the remote's default branch.
            'start_from' => ['nullable', Rule::enum(TaskStart::class)],
            'start_branch' => ['nullable', 'string', 'max:100', 'regex:'.self::BRANCH_PATTERN],
            'start_task_id' => ['nullable', 'required_if:start_from,task', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
            // Check out where it starts, with no message: its copy is made and its app started, no agent (TASK-006).
            'check_out' => ['nullable', 'boolean'],
            'agent_auto' => ['sometimes', 'boolean'],
        ], [
            'title.required' => __('Give the task a title.'),
            'start_task_id.required_if' => __('Pick the task to copy.'),
        ]);

        // Its own agent and model, picked under the new task's chat box (TASK-007); without one, Main's.
        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog);
        $start = TaskStart::tryFrom($validated['start_from'] ?? '') ?? ($project->git_remote_url !== null ? TaskStart::Branch : TaskStart::Main);

        if ($start === TaskStart::Branch && $project->git_remote_url === null) {
            throw ValidationException::withMessages(['start_from' => __('Connect a remote repository to start a task from one of its branches.')]);
        }

        $content = trim($validated['content'] ?? '');
        $attachments = array_values(Arr::wrap($request->file('attachments')));
        $starting = $content !== '' || $attachments !== [];
        $title = Str::squish($validated['title'] ?? '');
        $checkOut = ! $starting && $request->boolean('check_out');

        if ($checkOut && ! Task::getsCopies()) {
            throw ValidationException::withMessages(['check_out' => __('Tasks here work in Main, so there\'s nothing to check out. Describe the task instead.')]);
        }

        if (($starting || $checkOut) && Task::getsCopies() && $project->taskCopyLimitReached()) {
            throw self::copyLimitError($project);
        }

        $stage = $checkOut ? TaskStage::InProgress : TaskStage::Todo;
        $startBranch = $start === TaskStart::Branch ? ($validated['start_branch'] ?? null) : null;
        $startTask = $start === TaskStart::Task ? $project->tasks()->find($validated['start_task_id']) : null;

        $task = $project->tasks()->create([
            'title' => match (true) {
                $title !== '' => $title,
                $checkOut => self::checkOutTitle($start, $startBranch, $startTask),
                default => Task::titleFromPrompt($content),
            },
            'description' => $validated['description'] ?? null,
            'stage' => $stage,
            'position' => $project->nextTaskPosition($stage),
            'start_from' => $start,
            'start_branch' => $startBranch,
            'start_task_id' => $startTask?->id,
            ...($agent ? [...$agent, 'agent_auto' => $request->boolean('agent_auto')] : []),
        ]);

        if ($checkOut) {
            $checkouts->makeCopy($task);

            return to_route('projects.tasks.show', [$project, $task]);
        }

        if (! $starting) {
            return back();
        }

        $queue->send($task, $content, attachments: $attachments);

        return to_route('projects.tasks.show', [$project, $task]);
    }

    /**
     * What a new task can start from (TASK-005): the remote's branches (its default first, each with the open task
     * already working on it, if any), and the tasks with a running copy to copy.
     */
    public function startPoints(Project $project, GitRemote $remote): JsonResponse
    {
        Gate::authorize('update', $project);

        $branches = [];
        $default = null;
        $error = null;

        if ($project->git_remote_url !== null) {
            try {
                ['default' => $default, 'branches' => $names] = $remote->heads($project);
                usort($names, fn (string $a, string $b) => [$a !== $default, $a] <=> [$b !== $default, $b]);
                $users = $project->tasks()->where('stage', '!=', TaskStage::Done)->whereNull('pull_request_state')
                    ->where(fn ($query) => $query->whereIn('branch', $names)->orWhereIn('pull_request_branch', $names))
                    ->get(['id', 'title', 'branch', 'pull_request_branch']);

                $branches = array_map(function (string $name) use ($users) {
                    $user = $users->first(fn (Task $task) => $task->branch === $name || $task->pull_request_branch === $name);

                    return ['name' => $name, 'task' => $user?->only('id', 'title')];
                }, $names);
            } catch (GitException $e) {
                $error = $e->getMessage();
            }
        }

        return response()->json([
            'copies' => Task::getsCopies(),
            'remote' => $project->git_remote_url !== null,
            'default_branch' => $default,
            'branches' => $branches,
            'error' => $error,
            'tasks' => $project->tasks()
                ->whereHas('sandbox', fn ($query) => $query->where('status', SandboxStatus::Running))
                ->latest('updated_at')
                ->get(['id', 'title'])
                ->map(fn (Task $task) => $task->only('id', 'title')),
        ]);
    }

    /**
     * The workspace, on the task's chat.
     */
    public function show(Request $request, Project $project, Task $task): Response
    {
        Gate::authorize('view', $project);

        // A pull request merged, closed or just opened on GitHub shows on the task's next reload (GIT-023, GIT-024).
        RefreshPullRequestTasks::soon($project);

        return $this->renderWorkspace($request, $project, $task);
    }

    /**
     * Rename the task, change its notes, or move it to another column; for a pull request's task, whether to push after
     * each turn, fix failed checks automatically and address review comments automatically (GIT-031).
     */
    public function update(Request $request, Project $project, Task $task, PullRequestFixes $fixes, PullRequestReviews $reviews): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'stage' => ['sometimes', Rule::enum(TaskStage::class)],
            // A pull request's task (GIT-014, GIT-015).
            'pull_request_push' => ['sometimes', 'boolean'],
            'pull_request_autofix' => ['sometimes', 'boolean'],
            'pull_request_address_reviews' => ['sometimes', 'boolean'],
        ], [
            'title.required' => __('Give the task a title.'),
        ]);

        if (isset($validated['title'])) {
            $validated['title'] = Str::squish($validated['title']);
        }

        if (isset($validated['stage']) && $validated['stage'] !== $task->stage->value) {
            $validated['position'] = $project->nextTaskPosition(TaskStage::from($validated['stage']));
        }

        if (! $task->isPullRequest()) {
            unset($validated['pull_request_push'], $validated['pull_request_autofix'], $validated['pull_request_address_reviews']);
        }

        $fixingNow = ($validated['pull_request_autofix'] ?? false) && ! $task->pull_request_autofix;
        $addressingNow = ($validated['pull_request_address_reviews'] ?? false) && ! $task->pull_request_address_reviews;

        // Turned on again: the count of tries (or rounds) in a row starts over.
        $task->update([
            ...$validated,
            ...($fixingNow ? ['pull_request_fix_attempts' => 0] : []),
            ...($addressingNow ? ['pull_request_review_rounds' => 0] : []),
        ]);

        // Turned on: review comments already waiting go to the agent now rather than at the next review (GIT-031).
        if ($addressingNow) {
            try {
                $reviews->addressAutomatically($task);
            } catch (GitException $e) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            }
        }

        // Turned on while the checks are failing: start fixing them now rather than after the next push.
        if ($fixingNow && $task->pull_request_checks === 'failure' && ! $task->isWorking()) {
            try {
                if ($fixes->send($task)) {
                    $task->update(['pull_request_fix_attempts' => 1]);
                }
            } catch (GitException $e) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            }
        }

        return back();
    }

    /**
     * Push a pull request's task to its branch on GitHub (GIT-014).
     */
    public function pushPullRequest(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        return $this->syncPullRequest($task, TaskSyncStatus::Pushing);
    }

    /**
     * Bring commits pushed to the pull request since into the task's copy (GIT-014).
     */
    public function pullPullRequest(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        return $this->syncPullRequest($task, TaskSyncStatus::Pulling);
    }

    /**
     * Push the task's work to a branch of its own for a new pull request (GIT-024); the bar then links to GitHub's new
     * pull request page, filled in.
     */
    public function openPullRequest(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        if (($problem = OpenTaskPullRequest::start($task)) !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);
        }

        return back();
    }

    /**
     * A new preview link for the pull request's task (GIT-027): the old one stops working at once, and the pull request's
     * comment and Deployment get the new one (GIT-028).
     */
    public function newPreviewLink(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        if ($task->isPullRequest() && $task->pull_request_state === null) {
            $task->update(['preview_key' => Str::random(40)]);
            UpdatePullRequestPreview::for($task);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Made a new preview link. The old one no longer works.')]);
        }

        return back();
    }

    /**
     * Make the task's copy of the app again after it couldn't be made (TASK-003): on its pull request's branch for a
     * pull request's task (GIT-014), without running the agent.
     */
    public function retryCopy(Project $project, Task $task, PullRequestCheckouts $checkouts): RedirectResponse
    {
        Gate::authorize('update', $project);

        $problem = match (true) {
            $task->sandbox()->where('status', SandboxStatus::Failed)->doesntExist() => __('This task\'s copy of the app isn\'t one that failed.'),
            $task->isWorking() || $task->sync_status !== null => __("Wait for the task's agent to finish, or stop it."),
            // Its failed copy is replaced, so only the other tasks' copies count.
            ($limit = $project->taskCopyLimit()) !== null && $project->sandboxes()->whereNotNull('task_id')->where('task_id', '!=', $task->id)->count() >= $limit => self::copyLimitError($project)->getMessage(),
            default => null,
        };

        if ($problem !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);

            return back();
        }

        $checkouts->makeCopy($task);
        UpdatePullRequestPreview::for($task);

        return back();
    }

    protected function syncPullRequest(Task $task, TaskSyncStatus $direction): RedirectResponse
    {
        if (($problem = SyncPullRequestTask::start($task, $direction)) !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);
        }

        return back();
    }

    /**
     * Merge the task's work into Main (TASK-003). Main's agent is then asked to do what the app needs
     * (dependencies, migrations) and to resolve any conflicts; the task moves to Done.
     */
    public function apply(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        return $this->sync($project, $task, TaskSyncStatus::Applying);
    }

    /**
     * Merge Main's newer work into the task's copy of the app; the task's agent then brings the copy up to date.
     */
    public function updateFromMain(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        return $this->sync($project, $task, TaskSyncStatus::Updating);
    }

    /**
     * An error for a task that would need one copy of the app more than the project may run.
     */
    /**
     * A checked-out task's title (TASK-006): what it started from, until someone renames it.
     */
    protected static function checkOutTitle(TaskStart $start, ?string $branch, ?Task $from): string
    {
        return Str::limit(match ($start) {
            TaskStart::Branch => $branch ?? __('Default branch'),
            TaskStart::Task => __('Copy of :title', ['title' => $from?->title]),
            TaskStart::Main => __('Copy of Main'),
        }, 80, '');
    }

    public static function copyLimitError(Project $project): ValidationException
    {
        return ValidationException::withMessages([
            'content' => trans_choice('This project already runs :count task copy of the app, as many as it may. Apply or delete a task first.|This project already runs :count task copies of the app, as many as it may. Apply or delete a task first.', (int) $project->taskCopyLimit()),
        ]);
    }

    protected function sync(Project $project, Task $task, TaskSyncStatus $direction): RedirectResponse
    {
        $problem = match (true) {
            $task->isPullRequest() => __('A pull request\'s work goes back through GitHub: push it, or pull its newer commits.'),
            $task->sync_status !== null => __('Wait for the current merge to finish.'),
            ! $task->agentSandbox()?->external_id || $task->agentSandbox()->task_id === null => __("This task doesn't have its own copy of the app."),
            $task->isWorking() => __("Wait for the task's agent to finish, or stop it."),
            $direction === TaskSyncStatus::Applying && $project->status === ProjectStatus::Working => __("Wait for Main's agent to finish, or stop it."),
            default => null,
        };

        if ($problem) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problem]);

            return back();
        }

        $task->update(['sync_status' => $direction, 'sync_error' => null]);
        SyncTask::dispatch($task, $direction);

        return back();
    }

    /**
     * Delete the task and its chat, stopping its agent first.
     */
    public function destroy(Request $request, Project $project, Task $task, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $queue->stop($task);
        $task->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted “:title”.', ['title' => $task->title])]);

        $onTask = Str::before(url()->previous(), '?') === route('projects.tasks.show', [$project, $task]);

        return $onTask ? to_route('projects.board', $project) : back();
    }
}
