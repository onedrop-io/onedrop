<?php

namespace App\Http\Controllers;

use App\Enums\GroupRole;
use App\Http\Requests\StoreGroupRequest;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    /**
     * List the groups the user belongs to (all groups for admins).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $groups = ($user->is_admin ? Group::query() : $user->groups())
            ->withCount('members')
            ->orderBy('name')
            ->get();

        $roles = $user->groups()->pluck('group_user.role', 'groups.id');

        return Inertia::render('groups/index', [
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'members_count' => $group->members_count,
                'role' => $roles[$group->id] ?? null,
            ]),
        ]);
    }

    /**
     * Create a group owned by the current user.
     */
    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $group = Group::create($request->validated());

        $group->members()->attach($request->user(), ['role' => GroupRole::Owner->value]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('groups.show', $group);
    }

    /**
     * Show a group and its members.
     */
    public function show(Request $request, Group $group): Response
    {
        Gate::authorize('view', $group);

        return Inertia::render('groups/show', [
            'group' => $group->only('id', 'name', 'description'),
            'members' => $group->members()
                ->orderBy('name')
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                ]),
            'can' => [
                'update' => $request->user()->can('update', $group),
                'delete' => $request->user()->can('delete', $group),
            ],
        ]);
    }

    /**
     * Update a group's details.
     */
    public function update(StoreGroupRequest $request, Group $group): RedirectResponse
    {
        Gate::authorize('update', $group);

        $group->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group updated.')]);

        return to_route('groups.show', $group);
    }

    /**
     * Delete a group.
     */
    public function destroy(Group $group): RedirectResponse
    {
        Gate::authorize('delete', $group);

        $group->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted.')]);

        return to_route('groups.index');
    }
}
