<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectMessageController extends Controller
{
    /**
     * Send a message to the project's agent. While it's working the message is queued,
     * unless mode is "now", which stops the current run and sends this one instead.
     * Files can come along (AGT-006), with or without text.
     */
    public function store(Request $request, Project $project, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            ...Attachment::rules('content'),
            'mode' => ['nullable', Rule::in(['queue', 'now'])],
        ]);

        $queue->send(
            $project,
            $validated['content'] ?? '',
            now: ($validated['mode'] ?? 'queue') === 'now',
            attachments: array_values(Arr::wrap($request->file('attachments'))),
        );

        return to_route('projects.show', $project);
    }

    /**
     * Remove a queued message before it runs.
     */
    public function destroy(Project $project, Message $message): RedirectResponse
    {
        Gate::authorize('update', $project);

        abort_unless($message->project_id === $project->id && $message->queued, 404);

        $message->delete();

        return to_route('projects.show', $project);
    }
}
