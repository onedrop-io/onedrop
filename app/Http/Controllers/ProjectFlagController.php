<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\DatabaseException;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectFlagController extends Controller
{
    /**
     * The app's feature flags.
     */
    public function index(Project $project, WorkspaceFlags $flags): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['flags' => $flags->flags($sandbox)]);
    }

    /**
     * Ask the agent to put a feature behind a new flag.
     */
    public function store(Request $request, Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $feature = $request->validate(['feature' => ['required', 'string', 'max:2000']])['feature'];

        return $this->fromSandbox($project, fn () => [
            'queued' => (bool) $queue->send($project, WorkspaceFlags::addRequest($feature))->queued,
        ]);
    }

    /**
     * Turn a flag on or off.
     */
    public function update(Request $request, Project $project, string $flag, WorkspaceFlags $flags): JsonResponse
    {
        Gate::authorize('update', $project);

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($flags, $flag, $enabled) {
            $flags->set($sandbox, $flag, (bool) $enabled);

            return ['flags' => $flags->flags($sandbox)];
        });
    }

    /**
     * Ask the agent to take a flag out of the app, keeping the feature as it is now.
     */
    public function destroy(Project $project, string $flag, WorkspaceFlags $flags, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $flag, $flags, $queue) {
            $current = collect($flags->flags($sandbox))->firstWhere('key', $flag)
                ?? throw new DatabaseException(__("That flag isn't in your app anymore. It may have been removed."));

            return ['queued' => (bool) $queue->send($project, WorkspaceFlags::removeRequest($flag, $current['enabled']))->queued];
        });
    }

    /**
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
