<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Talking to a task's agent (TASK-001): like the main chat's, but only this task's run and queue are affected.
 */
class TaskMessageController extends Controller
{
    /**
     * Send a message to the task's agent. While it's working the message is queued, unless mode is "now",
     * which stops the task's current run and sends this one instead. Started from the board ("stay"),
     * the user stays there to start more.
     */
    public function store(Request $request, Project $project, Task $task, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            ...Attachment::rules('content'),
            'mode' => ['nullable', Rule::in(['queue', 'now'])],
            'stay' => ['sometimes', 'boolean'],
        ]);

        if ($task->needsCopy() && $project->taskCopyLimitReached()) {
            throw TaskController::copyLimitError();
        }

        $queue->send(
            $task,
            $validated['content'] ?? '',
            now: ($validated['mode'] ?? 'queue') === 'now',
            attachments: $request->file('attachments', []),
        );

        return $request->boolean('stay') ? back() : to_route('projects.tasks.show', [$project, $task]);
    }

    /**
     * Remove a message from the task's queue before it runs.
     */
    public function destroy(Project $project, Task $task, Message $message): RedirectResponse
    {
        Gate::authorize('update', $project);

        abort_unless($task->project_id === $project->id && $message->task_id === $task->id && $message->queued, 404);

        $message->delete();

        return to_route('projects.tasks.show', [$project, $task]);
    }

    /**
     * Stop the task's agent. Its queued messages are cancelled and handed back so the user can edit them.
     */
    public function stop(Project $project, Task $task, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $droppedAttachments = Attachment::query()->whereIn('message_id', $task->queuedMessages()->select('id'))->count();
        $queued = array_filter($queue->stop($task), fn (string $text) => $text !== '');

        if ($queued !== []) {
            Inertia::flash('draft', implode("\n\n", $queued));
        }

        if ($droppedAttachments > 0) {
            Inertia::flash('toast', ['type' => 'info', 'message' => trans_choice('The queued attachment was removed; attach it again to send it.|The :count queued attachments were removed; attach them again to send them.', $droppedAttachments)]);
        }

        return to_route('projects.tasks.show', [$project, $task]);
    }
}
