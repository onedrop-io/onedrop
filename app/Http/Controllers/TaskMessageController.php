<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\MessageChecks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
     * which stops the task's current run and sends this one instead, or "auto", where Jev decides (AGT-012); with
     * checks, a message can be held for the user to answer first (SECRET-002, REQ-003). Started from the board ("stay"),
     * the user stays there to start more.
     */
    public function store(Request $request, Project $project, Task $task, MessageChecks $checks): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            ...Attachment::rules('content'),
            ...MessageChecks::rules(),
            'mode' => ['nullable', Rule::in(['queue', 'now', 'auto'])],
            'stay' => ['sometimes', 'boolean'],
        ]);

        if ($task->needsCopy() && $project->taskCopyLimitReached()) {
            throw TaskController::copyLimitError();
        }

        $held = $checks->send(
            $task,
            $validated['content'] ?? '',
            $validated['mode'] ?? 'queue',
            attachments: array_values(Arr::wrap($request->file('attachments'))),
            interactive: $request->boolean('checks'),
            replies: Arr::only($validated, ['check', 'confirm_decision', 'send_secret', 'secret_name', 'secret_value']),
            agentContext: $validated['agent_context'] ?? null,
        );

        // Held for the user to answer first (a secret, or a change to an earlier decision); the composer keeps the text.
        if ($held !== null) {
            MessageChecks::rememberPrompt($request->user(), $task, $held);
        }

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
