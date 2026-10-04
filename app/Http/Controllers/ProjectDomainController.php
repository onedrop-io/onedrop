<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDomain;
use App\Sandbox\Domains\Connector;
use App\Sandbox\Domains\ProjectDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectDomainController extends Controller
{
    /**
     * The project's custom domains, their DNS records and status (DOM-001).
     */
    public function index(Project $project, ProjectDomains $domains): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($this->describe($project, $domains));
    }

    /**
     * Add a domain and start connecting it.
     */
    public function store(Request $request, Project $project, ProjectDomains $domains): JsonResponse
    {
        Gate::authorize('update', $project);

        $hostname = $request->validate(['hostname' => ['required', 'string', 'max:255']])['hostname'];
        $domains->add($project, $hostname);

        return response()->json($this->describe($project->fresh(), $domains));
    }

    /**
     * Make a domain the primary one (DOM-002).
     */
    public function update(Request $request, Project $project, ProjectDomain $domain, ProjectDomains $domains): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['primary' => ['required', 'accepted']]);
        $domains->makePrimary($domain);

        return response()->json($this->describe($project->fresh(), $domains));
    }

    /**
     * Check a domain's DNS now, and keep checking it if it's still waiting.
     */
    public function check(Project $project, ProjectDomain $domain, ProjectDomains $domains): JsonResponse
    {
        Gate::authorize('update', $project);

        $domains->check($domain);
        $domains->startChecking($domain);

        return response()->json($this->describe($project->fresh(), $domains));
    }

    public function destroy(Project $project, ProjectDomain $domain, ProjectDomains $domains): JsonResponse
    {
        Gate::authorize('update', $project);

        $domains->remove($domain);

        return response()->json($this->describe($project->fresh(), $domains));
    }

    /**
     * @return array{domains: list<array<string, mixed>>, unavailable: string|null, redirects: bool, url: string|null}
     */
    protected function describe(Project $project, ProjectDomains $domains): array
    {
        $connector = $domains->connector($project);

        return [
            'domains' => array_values($project->domains()->orderByDesc('primary')->orderBy('id')->get()->map(fn (ProjectDomain $domain): array => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'primary' => $domain->primary,
                'status' => $domain->via === null ? 'not_connected' : $domain->status->value,
                'error' => $domain->error,
                'records' => $domain->records ?? [],
                'checked_at' => $domain->checked_at?->toIso8601String(),
            ])->all()),
            // Why domains can't be connected where the project is (to be) published.
            'unavailable' => is_string($connector) ? $connector : null,
            'redirects' => $connector instanceof Connector && $connector->redirects(),
            'url' => $project->publish_status !== null ? $project->published_url : null,
        ];
    }
}
