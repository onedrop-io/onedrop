<?php

namespace App\Http\Controllers;

use App\Concerns\RendersWorkspace;
use App\Enums\ProjectStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Jobs\SyncTask;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
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

    /**
     * The project's board: every task, by column.
     */
    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        $tasks = $project->tasks()->get();

        return Inertia::render('projects/board', [
            'project' => $project->only('id', 'name', 'status'),
            'stages' => array_map(fn (TaskStage $stage) => ['value' => $stage->value, 'label' => $stage->label()], TaskStage::cases()),
            'tasks' => $tasks->map(fn (Task $task): array => [
                ...$task->only('id', 'title', 'description', 'stage', 'status'),
                'activity' => $task->isWorking() ? $task->currentActivity() : null,
                'updated_at' => $task->updated_at?->toIso8601String(),
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
     * Start a task with a first message (it runs right away, alongside the project's other agents), or,
     * with only a title, add a card to To do on the board without starting it.
     */
    public function store(Request $request, Project $project, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:80', 'required_without_all:content,attachments'],
            'description' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.Attachment::MAX_FILES],
            'attachments.*' => ['file', 'max:'.Attachment::MAX_KILOBYTES],
        ], [
            'title.required_without_all' => __('Give the task a title.'),
        ]);

        $content = trim($validated['content'] ?? '');
        $attachments = array_values(Arr::wrap($request->file('attachments')));
        $starting = $content !== '' || $attachments !== [];
        $title = Str::squish($validated['title'] ?? '');

        if ($starting && Task::getsCopies() && $project->taskCopyLimitReached()) {
            throw self::copyLimitError();
        }

        $task = $project->tasks()->create([
            'title' => $title !== '' ? $title : Task::titleFromPrompt($content),
            'description' => $validated['description'] ?? null,
            'stage' => TaskStage::Todo,
            'position' => $project->nextTaskPosition(TaskStage::Todo),
        ]);

        if (! $starting) {
            return back();
        }

        $queue->send($task, $content, attachments: $attachments);

        return to_route('projects.tasks.show', [$project, $task]);
    }

    /**
     * The workspace, on the task's chat.
     */
    public function show(Request $request, Project $project, Task $task): Response
    {
        Gate::authorize('view', $project);

        return $this->renderWorkspace($request, $project, $task);
    }

    /**
     * Rename the task, change its notes, or move it to another column.
     */
    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'stage' => ['sometimes', Rule::enum(TaskStage::class)],
        ], [
            'title.required' => __('Give the task a title.'),
        ]);

        if (isset($validated['title'])) {
            $validated['title'] = Str::squish($validated['title']);
        }

        if (isset($validated['stage']) && $validated['stage'] !== $task->stage->value) {
            $validated['position'] = $project->nextTaskPosition(TaskStage::from($validated['stage']));
        }

        $task->update($validated);

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
    public static function copyLimitError(): ValidationException
    {
        return ValidationException::withMessages([
            'content' => __('This project already runs :count task copies of the app. Apply or delete a task first.', ['count' => config('sandbox.max_task_copies')]),
        ]);
    }

    protected function sync(Project $project, Task $task, TaskSyncStatus $direction): RedirectResponse
    {
        $problem = match (true) {
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
