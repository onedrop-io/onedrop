<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Middleware\ResolveOrganization;
use App\Jobs\SuspendComputers;
use App\Models\Organization;
use App\Sandbox\Agents\AiCredits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationController extends Controller
{
    /**
     * Go to the new-project page of the organization the user used last (ORG-002), keeping anything flashed for it.
     */
    public function current(Request $request): RedirectResponse
    {
        $request->session()->reflash();

        return to_route('organizations.home', ResolveOrganization::current($request));
    }

    /**
     * Make another organization, owned by the user, on the hosted install (ORG-003). A self-hosted install has one.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(Organization::multiTenant(), 404);

        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];

        // Every organization gets free AI credits (CREDIT-001), so for now each person can own only one.
        if (app(AiCredits::class)->enabled() && $request->user()->organizations()->wherePivot('role', OrganizationRole::Owner->value)->exists()) {
            throw ValidationException::withMessages(['name' => __('You can own one organization for now.')]);
        }

        $organization = Organization::createNamed($name);
        $organization->addMember($request->user(), OrganizationRole::Owner);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Created :name.', ['name' => $organization->name])]);

        return to_route('organizations.home', $organization);
    }

    /**
     * General settings: its name, address, logo, computers and task copy limit (ORG-005, CMP-003, TASK-003), for its
     * owners and admins. Members have nothing to change here, so they go to its members.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $organization = ResolveOrganization::current($request);

        if (! $organization->isManagedBy($request->user())) {
            return to_route('organizations.members.index', $organization);
        }

        return Inertia::render('organizations/edit', [
            'details' => [...$organization->only('name', 'slug'), 'logo_url' => $organization->logoUrl()],
            // Whether its people get their own computer (CMP-003); null when the install turned them off.
            'computers' => config('sandbox.computers.enabled') ? ['enabled' => $organization->computers_enabled !== false] : null,
            // Most task copies one of its projects runs at once (TASK-003), and the install's own limit, if any.
            'taskCopies' => ['limit' => $organization->max_task_copies, 'install_limit' => config('sandbox.max_task_copies')],
        ]);
    }

    /**
     * Rename it, or move it to a new address.
     */
    public function update(Request $request): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        $organization->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('organizations', 'slug')->ignore($organization->id)],
        ], [
            'slug.regex' => __('Use lowercase letters, numbers and dashes.'),
            'slug.unique' => __('Another organization has that address.'),
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization updated.')]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Cap how many task copies one of its projects runs at once, or (left empty) don't (TASK-003). The install's own
     * limit still applies when it's lower.
     */
    public function updateTaskCopies(Request $request): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        $limit = $request->validate(['max_task_copies' => ['nullable', 'integer', 'min:1', 'max:1000']])['max_task_copies'] ?? null;
        $organization->update(['max_task_copies' => $limit === null ? null : (int) $limit]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task copies saved.')]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Turn its people's computers on or off (CMP-003). Turning them off puts running ones to sleep.
     */
    public function updateComputers(Request $request): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $organization->update(['computers_enabled' => $enabled]);

        if (! $enabled) {
            SuspendComputers::dispatch($organization);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $enabled ? __('Computers turned on.') : __('Computers turned off.')]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Upload a logo (ORG-005).
     */
    public function storeLogo(Request $request): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        $request->validate(['logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp,svg']]);

        $organization->storeLogo($request->file('logo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo saved.')]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Go back to its initial.
     */
    public function destroyLogo(Request $request): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        $organization->removeLogo();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo removed.')]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * The logo, for its members (the middleware turns everyone else away). SVGs can't run scripts.
     */
    public function logo(Request $request): StreamedResponse
    {
        $path = ResolveOrganization::current($request)->logo_path;

        abort_if($path === null || ! Storage::disk(Organization::logoDisk())->exists($path), 404);

        return Storage::disk(Organization::logoDisk())->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
