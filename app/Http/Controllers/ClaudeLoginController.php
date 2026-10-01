<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Whether Claude Code is signed in to the user's Claude subscription in a project's sandbox (AI-005).
 * Asks Claude Code itself (`claude auth status`); its login never leaves the sandbox.
 */
class ClaudeLoginController extends Controller
{
    /**
     * The sign-in status of the sandbox the project's main chat, or a task's (?task=), works in.
     */
    public function show(Request $request, Project $project, SandboxProvider $provider): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($this->status($this->conversation($request, $project), $provider));
    }

    /**
     * Once Claude Code is signed in, run the message that failed because it wasn't, so the chat carries on.
     * `waiting` says whether a message still waits for that, so the chat asks again (e.g. the check couldn't
     * reach the sandbox this time) instead of staying stuck.
     */
    public function resume(Request $request, Project $project, SandboxProvider $provider, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $conversation = $this->conversation($request, $project);
        $resumed = $conversation->getAttribute('sign_in_retry_message_id') !== null
            && $this->status($conversation, $provider)['signed_in'] === true
            && $queue->resumeAfterSignIn($conversation);

        return response()->json([
            'resumed' => $resumed,
            'waiting' => $conversation->getAttribute('sign_in_retry_message_id') !== null,
        ]);
    }

    /**
     * The project's main chat, or the task named by ?task=.
     */
    protected function conversation(Request $request, Project $project): Conversation
    {
        return $request->filled('task')
            ? $project->tasks()->findOrFail((int) $request->input('task'))
            : $project;
    }

    /**
     * @return array{signed_in: bool|null, email: string|null}
     */
    protected function status(Conversation $conversation, SandboxProvider $provider): array
    {
        $sandbox = $conversation->agentSandbox();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return ['signed_in' => null, 'email' => null];
        }

        try {
            // Exits non-zero when signed out, but still prints its status.
            $status = json_decode($provider->exec($sandbox->external_id, ['claude', 'auth', 'status', '--json'])->output, true);
        } catch (SandboxException) {
            $status = null;
        }

        if (! is_array($status)) {
            return ['signed_in' => null, 'email' => null];
        }

        $signedIn = ($status['loggedIn'] ?? false) === true && ($status['authMethod'] ?? null) === 'claude.ai';

        return [
            'signed_in' => $signedIn,
            'email' => $signedIn && is_string($status['email'] ?? null) ? $status['email'] : null,
        ];
    }
}
