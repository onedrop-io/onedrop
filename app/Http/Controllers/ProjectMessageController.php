<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\MessageChecks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectMessageController extends Controller
{
    /**
     * Send a message to the project's agent. While it's working the message is queued,
     * unless mode is "now", which stops the current run and sends this one instead, or "auto" (the chat's
     * composer), where Jev decides (AGT-012). With checks, a message with a secret or one that changes an earlier
     * decision is held for the user to answer first (SECRET-002, REQ-003). Files can come along (AGT-006).
     */
    public function store(Request $request, Project $project, MessageChecks $checks): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            ...Attachment::rules('content'),
            ...MessageChecks::rules(),
            'mode' => ['nullable', Rule::in(['queue', 'now', 'auto'])],
        ]);

        $held = $checks->send(
            $project,
            $validated['content'] ?? '',
            $validated['mode'] ?? 'queue',
            attachments: array_values(Arr::wrap($request->file('attachments'))),
            interactive: $request->boolean('checks'),
            replies: Arr::only($validated, ['check', 'confirm_decision', 'send_secret', 'secret_name', 'secret_value']),
            agentContext: $validated['agent_context'] ?? null,
        );

        // Held for the user to answer first (a secret, or a change to an earlier decision); the composer keeps the text.
        if ($held !== null) {
            MessageChecks::rememberPrompt($request->user(), $project, $held);
        }

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
