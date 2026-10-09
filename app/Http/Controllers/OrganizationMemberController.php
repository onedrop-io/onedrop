<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Middleware\ResolveOrganization;
use App\Jobs\SyncOrganizationSecrets;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who's in an organization and what they can do there (ORG-004).
 */
class OrganizationMemberController extends Controller
{
    /**
     * Its members, and its email domains for the people who manage them (ORG-004, ORG-008). Everyone in it sees who's
     * in it.
     */
    public function index(Request $request): Response
    {
        $organization = ResolveOrganization::current($request);
        $user = $request->user();

        return Inertia::render('organizations/members', [
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
            // Its email domains (ORG-008), for the people who can change them, on the hosted install.
            'domains' => Organization::multiTenant() && $organization->isManagedBy($user)
                ? $organization->domains()->orderBy('domain')->get()->map(fn (OrganizationDomain $domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'verified' => $domain->verified_at !== null,
                    'txt' => $domain->txtValue(),
                ])
                : null,
            'can' => [
                'update' => $organization->isManagedBy($user),
                'manage_owners' => $organization->isOwnedBy($user),
                // The one organization of a self-hosted install has everyone in it; accounts are deleted instead.
                'remove' => Organization::multiTenant(),
            ],
        ]);
    }

    /**
     * Change a member's role. Only owners make or unmake owners.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);
        $role = OrganizationRole::from($request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ])['role']);
        $current = $user->organizationRole($organization) ?? abort(404);

        $this->authorizeChange($request->user(), $organization, $current === OrganizationRole::Owner || $role === OrganizationRole::Owner);

        if ($current === OrganizationRole::Owner && $role !== OrganizationRole::Owner) {
            $this->ensureNotLastOwner($organization);
        }

        $organization->members()->updateExistingPivot($user->id, ['role' => $role->value]);
        $user->forgetOrganizationRoles();

        return to_route('organizations.members.index', $organization);
    }

    /**
     * Remove someone from the organization, or leave it. Only on the hosted install: a self-hosted install's one
     * organization has everyone in it.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $organization = ResolveOrganization::current($request);
        $current = $user->organizationRole($organization) ?? abort(404);
        $leaving = $request->user()->is($user);

        if (! $leaving) {
            $this->authorizeChange($request->user(), $organization, $current === OrganizationRole::Owner);
        }

        if (! Organization::multiTenant()) {
            throw ValidationException::withMessages(['member' => __('Everyone on this install is in its organization. Delete the account instead.')]);
        }

        if ($current === OrganizationRole::Owner) {
            $this->ensureNotLastOwner($organization);
        }

        $organization->removeMember($user);

        // Their GitHub token stops reaching the projects they leave behind (GIT-016).
        SyncOrganizationSecrets::dispatch($user);

        if ($leaving) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :organization.', ['organization' => $organization->name])]);

            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed.', ['name' => $user->name])]);

        return to_route('organizations.members.index', $organization);
    }

    /**
     * Owners and admins manage members; anything touching an owner is for owners only.
     */
    protected function authorizeChange(User $actor, Organization $organization, bool $touchesOwner): void
    {
        abort_unless($touchesOwner ? $organization->isOwnedBy($actor) : $organization->isManagedBy($actor), 403);
    }

    /**
     * @throws ValidationException
     */
    protected function ensureNotLastOwner(Organization $organization): void
    {
        if ($organization->ownerCount() <= 1) {
            throw ValidationException::withMessages(['member' => __('An organization must have at least one owner.')]);
        }
    }
}
