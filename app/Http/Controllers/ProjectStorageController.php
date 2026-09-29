<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\StorageException;
use App\Sandbox\WorkspaceStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Mime\MimeTypes;

class ProjectStorageController extends Controller
{
    /** Types shown in the browser when previewing; anything else (HTML, SVG, ...) is only downloaded. */
    protected const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];

    protected const PATH_RULES = ['required', 'string', 'max:1024'];

    /**
     * The app's buckets, with how many objects and bytes each holds.
     */
    public function index(Project $project, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['buckets' => $storage->buckets($sandbox)]);
    }

    public function store(Request $request, Project $project, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('update', $project);

        $name = $request->validate([
            'name' => ['required', 'string', 'regex:'.WorkspaceStorage::BUCKET_PATTERN],
        ], [
            'name.regex' => __('Use 3–63 lowercase letters, digits and dashes, starting and ending with a letter or digit.'),
        ])['name'];

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['buckets' => $storage->createBucket($sandbox, $name)]);
    }

    /**
     * Delete a bucket and everything in it.
     */
    public function destroy(Project $project, string $bucket, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['buckets' => $storage->deleteBucket($sandbox, $bucket)]);
    }

    /**
     * One folder of a bucket, or with a search query, matching objects anywhere in it.
     */
    public function objects(Request $request, Project $project, string $bucket, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'prefix' => ['nullable', 'string', 'max:1024'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => filled($validated['search'] ?? null)
            ? ['folders' => [], 'objects' => $storage->search($sandbox, $bucket, $validated['search'])]
            : $storage->list($sandbox, $bucket, $validated['prefix'] ?? ''));
    }

    /**
     * Upload one file, replacing any object at the same path.
     */
    public function upload(Request $request, Project $project, string $bucket, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'path' => self::PATH_RULES,
            'file' => ['required', 'file', 'max:'.WorkspaceStorage::MAX_UPLOAD_KILOBYTES],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => [
            'object' => $storage->upload($sandbox, $bucket, $validated['path'], (string) $request->file('file')->get()),
        ]);
    }

    public function folder(Request $request, Project $project, string $bucket, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('update', $project);

        $path = $request->validate(['path' => self::PATH_RULES])['path'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($storage, $bucket, $path) {
            $storage->createFolder($sandbox, $bucket, $path);

            return ['path' => trim($path, '/')];
        });
    }

    /**
     * Delete an object, or a folder and everything in it.
     */
    public function destroyObject(Request $request, Project $project, string $bucket, WorkspaceStorage $storage): JsonResponse
    {
        Gate::authorize('update', $project);

        $path = $request->validate(['path' => self::PATH_RULES])['path'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($storage, $bucket, $path) {
            $storage->delete($sandbox, $bucket, $path);

            return ['deleted' => trim($path, '/')];
        });
    }

    /**
     * An object's bytes: shown in the browser when it's a safe image type and `inline` is set, otherwise downloaded.
     */
    public function download(Request $request, Project $project, string $bucket, WorkspaceStorage $storage): Response|JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate(['path' => self::PATH_RULES, 'inline' => ['sometimes', 'boolean']]);
        $name = basename($validated['path']);
        $type = MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($name, PATHINFO_EXTENSION)))[0] ?? 'application/octet-stream';
        $inline = $request->boolean('inline') && in_array($type, self::INLINE_TYPES, true);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => response($storage->read($sandbox, $bucket, $validated['path']), 200, [
            'Content-Type' => $inline ? $type : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]));
    }

    /**
     * Ask the agent to set the app up to store files in a bucket.
     */
    public function agent(Request $request, Project $project, string $bucket, WorkspaceStorage $storage, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $uses = (string) ($request->validate(['uses' => ['nullable', 'string', 'max:2000']])['uses'] ?? '');

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $bucket, $uses, $storage, $queue) {
            if (! in_array($bucket, array_column($storage->buckets($sandbox), 'name'), true)) {
                throw new StorageException(__("There's no bucket called :bucket. Refresh to see the current list.", ['bucket' => $bucket]));
            }

            return ['queued' => (bool) $queue->send($project, WorkspaceStorage::setupRequest($bucket, $uses))->queued];
        });
    }

    /**
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
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
