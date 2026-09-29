<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
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
     * The sign-in status of the project's sandbox, or of a task's own copy with ?task=.
     */
    public function show(Request $request, Project $project, SandboxProvider $provider): JsonResponse
    {
        Gate::authorize('view', $project);

        $sandbox = $request->filled('task')
            ? $project->sandboxes()->where('task_id', (int) $request->query('task'))->first()
            : $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['signed_in' => null, 'email' => null]);
        }

        try {
            // Exits non-zero when signed out, but still prints its status.
            $status = json_decode($provider->exec($sandbox->external_id, ['claude', 'auth', 'status', '--json'])->output, true);
        } catch (SandboxException) {
            $status = null;
        }

        if (! is_array($status)) {
            return response()->json(['signed_in' => null, 'email' => null]);
        }

        $signedIn = ($status['loggedIn'] ?? false) === true && ($status['authMethod'] ?? null) === 'claude.ai';

        return response()->json([
            'signed_in' => $signedIn,
            'email' => $signedIn && is_string($status['email'] ?? null) ? $status['email'] : null,
        ]);
    }
}
