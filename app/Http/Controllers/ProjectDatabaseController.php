<?php

namespace App\Http\Controllers;

use App\Enums\HostedServiceKind;
use App\Enums\PublishTarget;
use App\Enums\SandboxStatus;
use App\Jobs\DeleteDatabaseCopy;
use App\Models\HostedService;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\DatabaseException;
use App\Sandbox\Hosting\HostingException;
use App\Sandbox\Hosting\ReleaseStorage;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProjectDatabaseController extends Controller
{
    /** Filter operators understood by the sandbox's database tool. */
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'null', 'notnull'];

    /**
     * Databases the project's app uses.
     */
    public function connections(Request $request, Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($request, $project, fn ($sandbox) => ['connections' => $database->connections($sandbox)]);
    }

    /**
     * Tables and views in one database.
     */
    public function tables(Request $request, Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('view', $project);

        $connection = $request->validate(['connection' => ['required', 'string', 'max:500']])['connection'];

        return $this->fromSandbox($request, $project, fn ($sandbox) => ['tables' => $database->tables($sandbox, $connection)]);
    }

    /**
     * One page of a table's rows, sorted and filtered.
     */
    public function rows(Request $request, Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'connection' => ['required', 'string', 'max:500'],
            'table' => ['required', 'string', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'max:500'],
            'direction' => ['nullable', 'in:asc,desc'],
            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*.column' => ['required', 'string', 'max:500'],
            'filters.*.operator' => ['required', 'in:'.implode(',', self::OPERATORS)],
            'filters.*.value' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->fromSandbox($request, $project, fn ($sandbox) => $database->rows($sandbox, $validated['connection'], [
            'table' => $validated['table'],
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 50),
            'sort' => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? 'asc',
            'filters' => array_values($validated['filters'] ?? []),
        ]));
    }

    /**
     * Save added, edited and deleted rows of one table in one transaction.
     */
    public function change(Request $request, Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'connection' => ['required', 'string', 'max:500'],
            'table' => ['required', 'string', 'max:500'],
            'inserts' => ['array', 'max:100'],
            'inserts.*' => ['array'],
            'updates' => ['array', 'max:500'],
            'updates.*.key' => ['required', 'array', 'min:1'],
            'updates.*.values' => ['required', 'array', 'min:1'],
            'deletes' => ['array', 'max:500'],
            'deletes.*' => ['required', 'array', 'min:1'],
        ]);

        $changes = [
            'table' => $validated['table'],
            'inserts' => array_values($validated['inserts'] ?? []),
            'updates' => array_values($validated['updates'] ?? []),
            'deletes' => array_values($validated['deletes'] ?? []),
        ];

        return $this->fromSandbox($request, $project, fn ($sandbox) => $database->change($sandbox, $validated['connection'], $changes));
    }

    /**
     * Run one SQL statement from the SQL runner.
     */
    public function query(Request $request, Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'connection' => ['required', 'string', 'max:500'],
            'sql' => ['required', 'string', 'max:50000'],
        ]);

        return $this->fromSandbox($request, $project, fn ($sandbox) => $database->query($sandbox, $validated['connection'], $validated['sql']));
    }

    /**
     * Download a consistent copy of a SQLite database, from the sandbox or the hosted app (HOST-007): whichever has it
     * uploads the copy to release storage through a signed link, and the browser is sent to a short-lived link to it.
     */
    public function download(Request $request, Project $project, WorkspaceDatabase $database, ReleaseStorage $releases): JsonResponse
    {
        Gate::authorize('update', $project);

        $connection = $request->validate(['connection' => ['required', 'string', 'max:500']])['connection'];

        if (! $releases->available($project)) {
            return response()->json(['message' => __('Downloading needs somewhere to put the copy: turn on Cloudflare in Settings → Hosting, or use an S3-compatible SANDBOX_SNAPSHOT_DISK.')], 409);
        }

        $hosted = $request->query('where') === 'hosted';
        $path = "database-copies/{$project->id}/".Str::lower(Str::random(24)).'.sqlite';
        $name = Str::slug($project->name).($hosted ? '-hosted' : '').'-'.now()->format('Y-m-d-His').'.sqlite';

        return $this->fromSandbox($request, $project, function ($target) use ($database, $releases, $project, $connection, $path, $name) {
            try {
                $disk = $releases->disk($project);
            } catch (HostingException $e) {
                throw new SandboxException($e->getMessage(), previous: $e);
            }

            ['url' => $url, 'headers' => $headers] = $disk->temporaryUploadUrl($path, now()->addMinutes(10));
            ['bytes' => $bytes] = $database->backup($target, $connection, $url, $headers);
            DeleteDatabaseCopy::dispatch($project->id, $path)->delay(now()->addHour());

            return [
                'url' => $disk->temporaryUrl($path, now()->addMinutes(10), ['ResponseContentDisposition' => "attachment; filename=\"{$name}\""]),
                'name' => $name,
                'bytes' => $bytes,
            ];
        });
    }

    /**
     * Run a call against the running sandbox, or the hosted app's machine when `where=hosted` (HOST-007), turning
     * failures into JSON errors.
     *
     * @param  callable(Sandbox|HostedService): array<string, mixed>  $call
     */
    protected function fromSandbox(Request $request, Project $project, callable $call): JsonResponse
    {
        if ($request->input('where') === 'hosted') {
            $sandbox = $project->publish_target === PublishTarget::Hosting && $project->publish_status !== null
                ? $project->hostedServices()->where('kind', HostedServiceKind::App)->first()
                : null;

            if ($sandbox === null) {
                return response()->json(['message' => __("The project isn't hosted, so it has no hosted database.")], 409);
            }
        } else {
            $sandbox = $project->sandbox;

            if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
                return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
            }
        }

        try {
            return response()->json($call($sandbox));
        } catch (DatabaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
