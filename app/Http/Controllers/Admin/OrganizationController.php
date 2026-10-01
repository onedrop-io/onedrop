<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every organization on the install, for platform admins (ORG-006). Counts only: it never opens their projects.
 */
class OrganizationController extends Controller
{
    /**
     * List them, newest first.
     */
    public function index(): Response
    {
        return Inertia::render('admin/organizations', [
            'organizations' => Organization::query()
                ->withCount(['members', 'projects'])
                ->with(['members' => fn ($query) => $query->wherePivot('role', OrganizationRole::Owner->value)->orderBy('name')])
                ->latest('id')
                ->get()
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'owners' => $organization->members->map(fn (User $owner): array => $owner->only('id', 'name', 'email'))->all(),
                    'members_count' => $organization->members_count,
                    'projects_count' => $organization->projects_count,
                    'created_at' => $organization->created_at?->toDateString(),
                ]),
        ]);
    }
}
