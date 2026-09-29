<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

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
     * How many times the sandbox's file watcher has seen files added, removed or renamed (FILE-004). The Files panel
     * checks this cheaply, without touching the sandbox, and reloads the tree only when it goes up; 0 means no watcher yet.
     */
    public function version(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['version' => $project->sandbox->files_version ?? 0]);
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
     * Save a file's contents.
     */
    public function update(Request $request, Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            'content' => ['present', 'nullable', 'string', 'max:'.WorkspaceFiles::MAX_BYTES],
        ]);
        $path = (string) $validated['path'];

        abort_unless(WorkspaceFiles::isSafePath($path), 422, __('That path is outside the project.'));

        return $this->fromSandbox($project, function ($sandbox) use ($files, $path, $validated) {
            $files->write($sandbox, $path, (string) $validated['content']);

            return ['path' => $path, 'saved' => true];
        });
    }

    /**
     * Create an empty file or folder.
     */
    public function store(Request $request, Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['file', 'dir'])],
        ]);
        $path = trim((string) $validated['path'], '/');

        abort_unless(WorkspaceFiles::isSafePath($path), 422, __('That path is outside the project.'));

        return $this->fromSandbox($project, function ($sandbox) use ($files, $path, $validated) {
            if (! $files->create($sandbox, $path, $validated['type'])) {
                return response()->json(['message' => __(':path already exists.', ['path' => $path])], 422);
            }

            return ['path' => $path, 'type' => $validated['type']];
        });
    }

    /**
     * Upload one file (e.g. from a folder the user picked) into the workspace.
     */
    public function upload(Request $request, Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            'file' => ['required', 'file', 'max:'.WorkspaceFiles::MAX_UPLOAD_KILOBYTES],
        ]);
        $path = (string) $validated['path'];

        abort_unless(WorkspaceFiles::isSafePath($path), 422, __('That path is outside the project.'));

        return $this->fromSandbox($project, function ($sandbox) use ($files, $path, $request) {
            $files->upload($sandbox, $path, (string) $request->file('file')->get());

            return ['path' => $path, 'uploaded' => true];
        });
    }

    /**
     * Download the workspace as a zip.
     */
    public function download(Project $project, WorkspaceFiles $files): Response|JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn ($sandbox) => response($files->zip($sandbox), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                (Str::slug($project->name) ?: 'project').'.zip',
            ),
        ]));
    }

    /**
     * Run a call against a running sandbox, turning failures into JSON errors.
     *
     * @template TResponse of JsonResponse|Response = JsonResponse
     *
     * @param  callable(Sandbox): (array<string, mixed>|TResponse)  $call
     * @return JsonResponse|TResponse
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse|Response
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            $result = $call($sandbox);

            return is_array($result) ? response()->json($result) : $result;
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
