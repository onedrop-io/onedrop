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
     * Rename or move a file or folder.
     */
    public function move(Request $request, Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'from' => ['required', 'string', 'max:1000'],
            'to' => ['required', 'string', 'max:1000', 'different:from'],
        ]);
        $from = trim((string) $validated['from'], '/');
        $to = trim((string) $validated['to'], '/');

        abort_unless(WorkspaceFiles::isEntryPath($from) && WorkspaceFiles::isEntryPath($to), 422, __('That path is outside the project.'));
        abort_if(str_starts_with($to.'/', $from.'/'), 422, __("A folder can't be moved inside itself."));

        return $this->fromSandbox($project, function ($sandbox) use ($files, $from, $to) {
            if (! $files->move($sandbox, $from, $to)) {
                return response()->json(['message' => __(':path already exists.', ['path' => $to])], 422);
            }

            return ['from' => $from, 'to' => $to];
        });
    }

    /**
     * Delete a file, or a folder and everything in it.
     */
    public function destroy(Request $request, Project $project, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('update', $project);

        $path = trim((string) $request->validate([
            'path' => ['required', 'string', 'max:1000'],
        ])['path'], '/');

        abort_unless(WorkspaceFiles::isEntryPath($path), 422, __('That path is outside the project.'));

        return $this->fromSandbox($project, function ($sandbox) use ($files, $path) {
            $files->delete($sandbox, $path);

            return ['path' => $path, 'deleted' => true];
        });
    }

    /**
     * Download the workspace as a zip, or with a `path`, one file as it is or one folder as a zip.
     */
    public function download(Request $request, Project $project, WorkspaceFiles $files): Response|JsonResponse
    {
        Gate::authorize('view', $project);

        $path = $request->validate([
            'path' => ['nullable', 'string', 'max:1000'],
        ])['path'] ?? null;
        $path = $path === null ? null : trim((string) $path, '/');

        abort_unless($path === null || WorkspaceFiles::isEntryPath($path), 422, __('That path is outside the project.'));

        return $this->fromSandbox($project, function ($sandbox) use ($files, $path, $project) {
            if ($path !== null && ! $files->isDirectory($sandbox, $path)) {
                return response($files->bytes($sandbox, $path), 200, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => self::attachment(basename($path)),
                ]);
            }

            $name = $path === null ? (Str::slug($project->name) ?: 'project') : basename($path);

            return response($files->zip($sandbox, $path), 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => self::attachment($name.'.zip'),
            ]);
        });
    }

    /**
     * A download header for a file name, with a plain-ASCII fallback for older browsers.
     */
    protected static function attachment(string $name): string
    {
        $name = str_replace('\\', '_', $name);

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            (string) preg_replace('/[^\x20-\x7e]|%/', '_', Str::ascii($name)),
        );
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
