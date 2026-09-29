<?php

namespace App\Http\Controllers;

use App\Enums\AgentHarness;
use App\Models\Sandbox;
use App\Models\Task;
use App\Sandbox\Agents\ClaudeCodeEvents;
use App\Sandbox\Agents\OpenCodeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Webhooks for the agent forwarder and file watcher running inside a sandbox.
 */
class SandboxEventController extends Controller
{
    /**
     * Store a batch of agent events, in order, in the project's main chat, or in a task's when a task's
     * run sent them (TASK-001). The forwarder says which agent sent them (older forwarders only ran OpenCode and don't).
     */
    public function store(Request $request, Sandbox $sandbox, ?Task $task = null): JsonResponse
    {
        abort_if($task !== null && $task->project_id !== $sandbox->project_id, 404);
        abort_unless($task ? $task->acceptsEventsToken($request->bearerToken()) : $sandbox->acceptsEventsToken($request->bearerToken()), 401);

        $validated = $request->validate([
            'agent' => ['nullable', Rule::enum(AgentHarness::class)],
            'events' => ['required', 'array', 'max:500'],
            'events.*' => ['array'],
        ]);

        $events = match (AgentHarness::tryFrom($validated['agent'] ?? '') ?? AgentHarness::OpenCode) {
            AgentHarness::OpenCode => app(OpenCodeEvents::class),
            AgentHarness::ClaudeCode => app(ClaudeCodeEvents::class),
        };

        $conversation = $task ?? $sandbox->project;

        foreach ($validated['events'] as $event) {
            $events->apply($conversation, $event);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The sandbox's file watcher saw files added, removed or renamed (FILE-004), so the Files panel should reload its tree.
     * Authenticated by the signed address the sandbox was created with.
     */
    public function filesChanged(Sandbox $sandbox): JsonResponse
    {
        $sandbox->increment('files_version');

        return response()->json(['version' => $sandbox->files_version]);
    }
}
