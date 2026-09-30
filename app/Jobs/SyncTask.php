<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\TaskStage;
use App\Enums\TaskSyncStatus;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\TaskCopies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Apply a task's work to Main, or bring Main's newer work into the task (TASK-003), as a git merge. What the
 * app then needs (dependencies, migrations, restarting) is handed to the receiving side's agent, which knows its stack.
 */
class SyncTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Task $task, public TaskSyncStatus $direction) {}

    public function handle(TaskCopies $copies, AgentQueue $queue): void
    {
        $task = $this->task;
        $project = $task->project;
        $applying = $this->direction === TaskSyncStatus::Applying;

        try {
            $conflicts = $applying ? $copies->apply($task) : $copies->updateFromMain($task);
        } catch (SandboxException $e) {
            report($e);
            $task->update(['sync_status' => null, 'sync_error' => $e->getMessage()]);

            return;
        }

        $task->update(['sync_status' => null, 'sync_error' => null]);

        if (! $applying) {
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => $conflicts === [] ? 'Brought in the latest from Main' : 'Brought in the latest from Main, with conflicts']);
            $queue->send($task, self::handOff('Main', $conflicts, fromMain: true));

            return;
        }

        $task->messages()->create(['role' => MessageRole::Activity, 'content' => $conflicts === [] ? 'Applied to Main' : 'Applied to Main; Main\'s agent is resolving conflicts']);
        $project->messages()->create(['role' => MessageRole::Activity, 'content' => "Applied task “{$task->title}”"]);
        $task->update(['stage' => TaskStage::Done, 'position' => $project->nextTaskPosition(TaskStage::Done), 'applied_at' => now()]);

        // Its work is in Main now; a later message makes a fresh copy from there.
        $task->destroyCopy();

        $queue->send($project, self::handOff("the task “{$task->title}”", $conflicts, fromMain: false));
    }

    /**
     * What the receiving agent is asked to do after the merge. Nothing here knows the stack: the agent does.
     *
     * @param  list<string>  $conflicts
     */
    public static function handOff(string $source, array $conflicts, bool $fromMain): string
    {
        $what = $fromMain
            ? 'The latest work from Main was just merged into this task\'s copy of the app.'
            : "The work from {$source} (done by another agent in a separate copy of the app) was just merged in here.";

        $resolve = $conflicts === [] ? '' : "\n\nThe merge stopped with conflicts in: ".implode(', ', $conflicts).'. Resolve them so both sides\' changes work together, then finish the merge with `git add -A && git commit --no-edit`.';

        return $what.$resolve."\n\nBring this copy up to date with it: install dependencies if lockfiles or manifests changed, apply any database migrations or schema changes it added, and restart the dev server with /opt/onedrop/restart if it needs it. Then check the preview works and fix anything the merge broke. If nothing needed doing, say so in one line.";
    }
}
