<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\OrganizationSecret;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\OrganizationSecrets;
use App\Sandbox\SandboxException;
use App\Sandbox\SecretsException;
use App\Sandbox\WorkspaceSecrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectSecretController extends Controller
{
    /** Env var names: letters, digits and underscores, not starting with a digit. */
    protected const NAME_RULES = ['required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'];

    /**
     * The names of the app's secrets (never their values), of its organization's that reach it (SECRET-003), and
     * whose GitHub token it has as GITHUB_TOKEN, unless one of those has the name (GIT-016).
     */
    public function index(Project $project, WorkspaceSecrets $secrets, OrganizationSecrets $organizationSecrets): JsonResponse
    {
        Gate::authorize('view', $project);

        $organization = OrganizationSecret::query()->reaching($project)->orderBy('name')->pluck('name')->all();
        $github = ! in_array(OrganizationSecrets::GITHUB_TOKEN, $organization, true) && $organizationSecrets->ownerGitHubToken($project) !== null
            ? $project->user->name
            : null;

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => [
            'secrets' => $secrets->names($sandbox),
            'organization' => $organization,
            'github' => $github,
        ]);
    }

    /**
     * One secret's value, for revealing or copying it.
     */
    public function show(Request $request, Project $project, WorkspaceSecrets $secrets): JsonResponse
    {
        Gate::authorize('update', $project);

        $name = $request->validate(['name' => self::NAME_RULES])['name'];

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['name' => $name, 'value' => $secrets->reveal($sandbox, $name)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Add one or more secrets, e.g. pasted from an .env file. Existing names are only
     * overwritten when the user confirmed it by listing them in "replace".
     */
    public function store(Request $request, Project $project, WorkspaceSecrets $secrets): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'secrets' => ['required', 'array', 'min:1', 'max:100'],
            'secrets.*.name' => [...self::NAME_RULES, 'distinct'],
            'secrets.*.value' => ['present', 'nullable', 'string', 'max:20000'],
            'replace' => ['sometimes', 'array'],
            'replace.*' => ['string'],
        ]);

        $values = $request->collect('secrets')->mapWithKeys(fn (array $secret) => [$secret['name'] => $secret['value'] ?? ''])->all();

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => [
            'secrets' => $secrets->set($sandbox, $values, $validated['replace'] ?? []),
        ]);
    }

    /**
     * Change a secret's value.
     */
    public function update(Request $request, Project $project, WorkspaceSecrets $secrets): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => self::NAME_RULES,
            'value' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => [
            'secrets' => $secrets->set($sandbox, [$validated['name'] => $validated['value'] ?? ''], [$validated['name']]),
        ]);
    }

    public function destroy(Request $request, Project $project, WorkspaceSecrets $secrets): JsonResponse
    {
        Gate::authorize('update', $project);

        $name = $request->validate(['name' => self::NAME_RULES])['name'];

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['secrets' => $secrets->delete($sandbox, $name)]);
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
        } catch (SecretsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
