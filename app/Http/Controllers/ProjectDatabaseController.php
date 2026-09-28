<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\DatabaseException;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectDatabaseController extends Controller
{
    /** Filter operators understood by the sandbox's database tool. */
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'null', 'notnull'];

    /**
     * Databases the project's app uses.
     */
    public function connections(Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn ($sandbox) => ['connections' => $database->connections($sandbox)]);
    }

    /**
     * Tables and views in one database.
     */
    public function tables(Request $request, Project $project, WorkspaceDatabase $database): JsonResponse
    {
        Gate::authorize('view', $project);

        $connection = $request->validate(['connection' => ['required', 'string', 'max:500']])['connection'];

        return $this->fromSandbox($project, fn ($sandbox) => ['tables' => $database->tables($sandbox, $connection)]);
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

        return $this->fromSandbox($project, fn ($sandbox) => $database->rows($sandbox, $validated['connection'], [
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

        return $this->fromSandbox($project, fn ($sandbox) => $database->change($sandbox, $validated['connection'], $changes));
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

        return $this->fromSandbox($project, fn ($sandbox) => $database->query($sandbox, $validated['connection'], $validated['sql']));
    }

    /**
     * Run a call against a running sandbox, turning failures into JSON errors.
     *
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
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
