<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\ServerLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectLogController extends Controller
{
    /**
     * New output from the app's dev server since the given offset.
     */
    public function index(Request $request, Project $project, ServerLog $log): JsonResponse
    {
        Gate::authorize('view', $project);

        $offset = $request->validate(['offset' => ['nullable', 'integer', 'min:0']])['offset'] ?? null;
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($log->read($sandbox, $offset === null ? null : (int) $offset));
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
