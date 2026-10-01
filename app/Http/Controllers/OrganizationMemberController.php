<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Who's in an organization and what they can do there (ORG-004).
 */
class OrganizationMemberController extends Controller
{
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

        return to_route('organizations.edit', $organization);
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

        if ($leaving) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :organization.', ['organization' => $organization->name])]);

            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed.', ['name' => $user->name])]);

        return to_route('organizations.edit', $organization);
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
