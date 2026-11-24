<?php

namespace App\Sandbox;

use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Http\Controllers\TaskController;
use App\Jobs\ForkTaskSandbox;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Checking a pull request out as a task (GIT-014): its own copy of the app, on the pull request's branch, with no agent
 * run. By hand from its page, or by itself for every pull request (GIT-026).
 */
class PullRequestCheckouts
{
    /**
     * The task a pull request is checked out as, if it is.
     */
    public function taskFor(Project $project, int $number): ?Task
    {
        return $project->tasks()->where('pull_request_number', $number)->first();
    }

    /**
     * Why the pull request can't be checked out as a task, or null when it can.
     *
     * @param  array<string, mixed>  $pull
     */
    public function problem(Project $project, array $pull): ?string
    {
        if (! Task::getsCopies()) {
            return __('Checking out needs tasks to get their own copy of the app, which is turned off here (SANDBOX_TASK_COPIES).');
        }

        if (! in_array($pull['state'], ['open', 'draft'], true)) {
            return __('Only open pull requests can be checked out.');
        }

        if ($project->taskCopyLimitReached()) {
            return TaskController::copyLimitError($project)->getMessage();
        }

        return null;
    }

    /**
     * A task for the pull request, without its copy: its first message makes one (TASK-003).
     *
     * @param  array<string, mixed>  $pull
     */
    public function newTask(Project $project, array $pull): Task
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

    /**
     * Check the pull request out as a new task, making its copy now (call problem() first).
     *
     * @param  array<string, mixed>  $pull
     */
    public function checkOut(Project $project, array $pull): Task
    {
        $task = $this->newTask($project, $pull);
        $this->makeCopy($task);

        return $task;
    }

    /**
     * Make the task's copy on its pull request's branch: again, for one whose copy was removed (GIT-026).
     */
    public function makeCopy(Task $task): void
    {
        // Working on its copy: messages sent meanwhile wait for it (see ForkTaskSandbox).
        $task->update(['status' => ProjectStatus::Working, 'sync_status' => TaskSyncStatus::Forking]);
        $task->sandbox()->updateOrCreate([], ['provider' => $task->project->sandboxProvider(), 'status' => SandboxStatus::Creating, 'external_id' => null, 'error' => null]);
        ForkTaskSandbox::dispatch($task, checkOutOnly: true);
    }
}
