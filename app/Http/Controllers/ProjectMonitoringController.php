<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxMonitoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectMonitoringController extends Controller
{
    /**
     * Request and resource metrics for the project's sandbox.
     */
    public function show(Request $request, Project $project, SandboxMonitoring $monitoring): JsonResponse
    {
        Gate::authorize('view', $project);

        $ranges = array_keys(SandboxMonitoring::RANGES);
        $validated = $request->validate([
            'range' => ['nullable', Rule::in($ranges)],
            'infra_range' => ['nullable', Rule::in($ranges)],
            'traffic' => ['nullable', Rule::in(['all', 'published'])],
        ]);

        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json([
                'application' => $monitoring->application($sandbox, $validated['range'] ?? '24h', ($validated['traffic'] ?? 'all') === 'published'),
                'infrastructure' => $monitoring->infrastructure($sandbox, $validated['infra_range'] ?? '1h'),
            ]);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
