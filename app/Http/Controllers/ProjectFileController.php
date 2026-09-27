<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectFileController extends Controller
{
    /**
     * List the files in the project's sandbox.
     */
    public function index(Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn ($sandbox) => ['files' => $files->list($sandbox)]);
    }

    /**
     * Show one file's contents.
     */
    public function show(Request $request, Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('view', $project);

        $path = (string) $request->validate([
            'path' => ['required', 'string', 'max:1000'],
        ])['path'];

        abort_unless(WorkspaceFiles::isSafePath($path), 422, __('That path is outside the project.'));

        return $this->fromSandbox($project, fn ($sandbox) => $files->read($sandbox, $path));
    }

    /**
     * Run a read against a running sandbox, turning failures into JSON errors.
     *
     * @param  callable(Sandbox): array<string, mixed>  $read
     */
    protected function fromSandbox(Project $project, callable $read): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($read($sandbox));
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
