<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

        $organization = Organization::createNamed($name);
        $organization->addMember($request->user(), OrganizationRole::Owner);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Created :name.', ['name' => $organization->name])]);

        return to_route('organizations.home', $organization);
    }

    /**
     * Its name, address and members (ORG-004, ORG-005).
     */
    public function edit(Request $request): Response
    {
        $organization = ResolveOrganization::current($request);
        $user = $request->user();

        return Inertia::render('organizations/edit', [
            'details' => [...$organization->only('name', 'slug'), 'logo_url' => $organization->logoUrl()],
            'members' => $organization->members()
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                    'joined_at' => $member->pivot->created_at?->toIso8601String(),
                ]),
            'can' => [
                'update' => $organization->isManagedBy($user),
                'manage_owners' => $organization->isOwnedBy($user),
                // The one organization of a self-hosted install has everyone in it; accounts are deleted instead.
                'remove' => Organization::multiTenant(),
            ],
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

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
