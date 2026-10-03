<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectMessageController extends Controller
{
    /**
     * Send a message to the project's agent. While it's working the message is queued, unless mode is "now", which
     * stops the current run and sends this one instead. Files can come along (AGT-006), with or without text.
     */
    public function store(Request $request, Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            ...Attachment::rules('content'),
            'mode' => ['nullable', Rule::in(['queue', 'now'])],
        ]);

        $message = $queue->send(
            $project,
            $validated['content'] ?? '',
            now: ($validated['mode'] ?? 'queue') === 'now',
            attachments: array_values(Arr::wrap($request->file('attachments'))),
        );

        return response()->json(['id' => $message->id, 'queued' => (bool) $message->queued], 201);
    }

    /**
     * Remove a queued message before it runs.
     */
    public function destroy(Project $project, Message $message): Response
    {
        Gate::authorize('update', $project);

        abort_unless($message->project_id === $project->id && $message->queued, 404);

        $message->delete();

        return response()->noContent();
    }
}
