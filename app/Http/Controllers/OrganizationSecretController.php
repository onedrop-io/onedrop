<?php

namespace App\Http\Controllers;

use App\Enums\ProjectKind;
use App\Http\Middleware\ResolveOrganization;
use App\Jobs\SyncOrganizationSecrets;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Sandbox\OrganizationSecrets;
use App\Sandbox\WorkspaceDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * An organization's secrets (SECRET-003): environment variables in its projects' sandboxes, for all of its projects or
 * chosen ones, managed by its owners and admins. A value is never sent back once saved.
 */
class OrganizationSecretController extends Controller
{
    /** Most secrets one organization keeps. */
    public const MAX_SECRETS = 100;

    /**
     * Add a secret.
     */
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->managed($request);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'not_regex:/^ONEDROP_/i',
                Rule::unique('organization_secrets', 'name')->where('organization_id', $organization->id),
            ],
            'value' => ['required', 'string', 'max:20000'],
            ...$this->projectRules($organization),
        ], [
            'name.regex' => __('Use letters, digits and underscores, not starting with a digit.'),
            'name.not_regex' => __('Names starting with ONEDROP_ are the platform\'s own.'),
            'name.unique' => __('The organization already has a secret with that name.'),
        ]);

        if ($organization->secrets()->count() >= self::MAX_SECRETS) {
            throw ValidationException::withMessages(['name' => __('An organization can keep up to :count secrets.', ['count' => self::MAX_SECRETS])]);
        }

        $this->ensureFits($organization, $validated['name'], $validated['value']);

        $secret = $organization->secrets()->create([
            'name' => $validated['name'],
            'value' => $validated['value'],
            'all_projects' => $validated['all_projects'],
        ]);
        $secret->projects()->sync($validated['all_projects'] ? [] : $validated['projects']);

        SyncOrganizationSecrets::dispatch($organization);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :name.', ['name' => $secret->name])]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Change which projects it reaches, and its value when a new one is given (left empty to keep it).
     */
    public function update(Request $request, OrganizationSecret $secret): RedirectResponse
    {
        $organization = $this->managed($request);

        abort_unless($secret->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'value' => ['nullable', 'string', 'max:20000'],
            ...$this->projectRules($organization),
        ]);

        if (filled($validated['value'] ?? null)) {
            $this->ensureFits($organization, $secret->name, $validated['value']);
            $secret->value = $validated['value'];
        }

        $secret->all_projects = $validated['all_projects'];
        // Saved even when only its projects changed, so "updated" moves too.
        $secret->touch();
        $secret->projects()->sync($validated['all_projects'] ? [] : $validated['projects']);

        SyncOrganizationSecrets::dispatch($organization);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved :name.', ['name' => $secret->name])]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Delete a secret: it's taken out of every sandbox it was in.
     */
    public function destroy(Request $request, OrganizationSecret $secret): RedirectResponse
    {
        $organization = $this->managed($request);

        abort_unless($secret->organization_id === $organization->id, 404);

        $secret->delete();

        SyncOrganizationSecrets::dispatch($organization);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted :name.', ['name' => $secret->name])]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * For all projects, or chosen ones of the organization's (not people's computers).
     *
     * @return array<string, mixed>
     */
    protected function projectRules(Organization $organization): array
    {
        return [
            'all_projects' => ['required', 'boolean'],
            'projects' => ['exclude_if:all_projects,true', 'required', 'array', 'min:1'],
            'projects.*' => ['integer', 'distinct', Rule::exists('projects', 'id')->where('organization_id', $organization->id)->where('kind', ProjectKind::App->value)],
        ];
    }

    /**
     * Every secret goes to a sandbox in one command's environment, which Linux caps, so all of them together must fit.
     *
     * @throws ValidationException
     */
    protected function ensureFits(Organization $organization, string $name, string $value): void
    {
        $secrets = [...$organization->secrets()->get()->mapWithKeys(fn (OrganizationSecret $secret) => [$secret->name => $secret->value])->all(), $name => $value];

        if (strlen(OrganizationSecrets::contents($secrets)) > WorkspaceDatabase::MAX_REQUEST_BYTES) {
            throw ValidationException::withMessages(['value' => __("That's more than the organization's secrets can hold together (about 90 KB).")]);
        }
    }

    protected function managed(Request $request): Organization
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        return $organization;
    }
}
