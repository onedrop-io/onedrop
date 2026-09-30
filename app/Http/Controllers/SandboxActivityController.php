<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SandboxActivityController extends Controller
{
    /**
     * The workspace is open on the project (or a task's own copy of it): keep its sandbox from being suspended for
     * sitting idle, and wake it if it was (SBX-007). `woke` says it had been asleep, so the workspace reloads its preview.
     */
    public function store(Request $request, Project $project, SandboxProvider $provider): JsonResponse
    {
        Gate::authorize('view', $project);

        $sandbox = $request->filled('task')
            ? $project->sandboxes()->where('task_id', $request->integer('task'))->first()
            : $project->sandbox;

        return response()->json(['woke' => $sandbox?->wake($provider) ?? false]);
    }
}
