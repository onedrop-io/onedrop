<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Services tab (SVC-001): what runs in the project's sandbox, container logs, and restarting things.
 */
class ProjectServiceController extends Controller
{
    /**
     * The preview server, the sandbox's containers and its open ports.
     */
    public function index(Project $project, SandboxServices $services): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $services->describe($sandbox));
    }

    /**
     * A container's latest log lines: 200, or up to 2,000 with `lines`.
     */
    public function logs(Request $request, Project $project, SandboxServices $services, string $name): JsonResponse
    {
        Gate::authorize('view', $project);

        $lines = (int) ($request->validate(['lines' => ['sometimes', 'integer', 'min:1', 'max:'.SandboxServices::MAX_LOG_LINES]])['lines'] ?? SandboxServices::LOG_LINES);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['logs' => $services->logs($sandbox, $name, $lines)]);
    }

    /**
     * Every container's latest log lines merged in time order, each labelled with its service: 200, or up to 2,000
     * with `lines`.
     */
    public function allLogs(Request $request, Project $project, SandboxServices $services): JsonResponse
    {
        Gate::authorize('view', $project);

        $lines = (int) ($request->validate(['lines' => ['sometimes', 'integer', 'min:1', 'max:'.SandboxServices::MAX_LOG_LINES]])['lines'] ?? SandboxServices::LOG_LINES);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['logs' => $services->mergedLogs($sandbox, $lines)]);
    }

    /**
     * Restart, stop or start a container.
     */
    public function update(Request $request, Project $project, SandboxServices $services, string $name): JsonResponse
    {
        Gate::authorize('update', $project);

        $action = $request->validate(['action' => ['required', Rule::in(SandboxServices::ACTIONS)]])['action'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($services, $name, $action) {
            $services->act($sandbox, $name, $action);

            return ['ok' => true];
        });
    }

    /**
     * Restart the preview server.
     */
    public function restartPreview(Project $project, SandboxServices $services): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($services) {
            $services->restartPreview($sandbox);

            return ['ok' => true];
        });
    }

    /**
     * Run against the project's running sandbox, turning sandbox errors into messages.
     *
     * @param  callable(Sandbox): array<string, mixed>  $callback
     */
    protected function fromSandbox(Project $project, callable $callback): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($callback($sandbox));
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
