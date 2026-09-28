<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\DatabaseException;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxGrowth;
use App\Sandbox\WorkspaceAnalytics;
use App\Sandbox\WorkspaceAuth;
use App\Sandbox\WorkspaceSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectGrowthController extends Controller
{
    /**
     * Visitor analytics, custom events, how many of the app's users signed in, and the latest SEO scan.
     */
    public function show(Request $request, Project $project, SandboxGrowth $growth, WorkspaceAnalytics $events, WorkspaceAuth $auth, WorkspaceSeo $seo): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(SandboxGrowth::RANGES))],
            'traffic' => ['nullable', Rule::in(['all', 'published'])],
        ]);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($validated, $growth, $events, $auth, $seo) {
            $range = $validated['range'] ?? '7d';
            $publishedOnly = ($validated['traffic'] ?? 'all') === 'published';
            $analytics = $growth->analytics($sandbox, $range, $publishedOnly);

            try {
                $signedIn = $auth->signedInSince($sandbox, $analytics['since']);
            } catch (DatabaseException|SandboxException) {
                // No sign-in in the app (yet), or its users can't be read: just leave the number out.
                $signedIn = null;
            }

            return [
                'analytics' => $analytics,
                'events' => $events->events($sandbox, $range, $publishedOnly),
                'signed_in_users' => $signedIn,
                'seo' => $seo->report($sandbox),
            ];
        });
    }

    /**
     * Ask the agent to check the app's SEO, and optionally fix what it finds.
     */
    public function scan(Request $request, Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $fix = $request->validate(['fix' => ['sometimes', 'boolean']])['fix'] ?? false;

        return $this->fromSandbox($project, fn () => [
            'queued' => (bool) $queue->send($project, WorkspaceSeo::request((bool) $fix))->queued,
        ]);
    }

    /**
     * Ask the agent to add custom analytics events, optionally the ones the user described.
     */
    public function addEvents(Request $request, Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $events = $request->validate(['events' => ['nullable', 'string', 'max:1000']])['events'] ?? null;

        return $this->fromSandbox($project, fn () => [
            'queued' => (bool) $queue->send($project, WorkspaceAnalytics::request($events))->queued,
        ]);
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
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
