<?php

namespace App\Http\Controllers;

use App\Enums\GroupRole;
use App\Http\Requests\StoreGroupMemberRequest;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GroupMemberController extends Controller
{
    /**
     * Add an existing user to the group.
     */
    public function store(StoreGroupMemberRequest $request, Group $group): RedirectResponse
    {
        $user = User::where('email', $request->validated('email'))->firstOrFail();

        $group->members()->attach($user, ['role' => $request->validated('role')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $user->name])]);

        return to_route('groups.show', [$group->organization, $group]);
    }

    /**
     * Change a member's role.
     */
    public function update(Request $request, Group $group, User $user): RedirectResponse
    {
        Gate::authorize('update', $group);

        $role = GroupRole::from($request->validate([
            'role' => ['required', Rule::enum(GroupRole::class)],
        ])['role']);

        $currentRole = $group->roleOf($user) ?? abort(404);

        if ($currentRole === GroupRole::Owner && $role !== GroupRole::Owner) {
            $this->ensureNotLastOwner($group);
        }

        $group->members()->updateExistingPivot($user->id, ['role' => $role->value]);

        return to_route('groups.show', [$group->organization, $group]);
    }

    /**
     * Remove a member from the group. Members may remove themselves (leave).
     */
    public function destroy(Request $request, Group $group, User $user): RedirectResponse
    {
        $isLeaving = $request->user()->is($user);

        if (! $isLeaving) {
            Gate::authorize('update', $group);
        }

        $currentRole = $group->roleOf($user) ?? abort(404);

        if ($currentRole === GroupRole::Owner) {
            $this->ensureNotLastOwner($group);
        }

        $group->members()->detach($user);

        if ($isLeaving && ! $request->user()->can('view', $group)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :group.', ['group' => $group->name])]);

            return to_route('groups.index', $group->organization);
        }

        return to_route('groups.show', [$group->organization, $group]);
    }

    /**
     * Groups must always keep at least one owner.
     *
     * @throws ValidationException
     */
    protected function ensureNotLastOwner(Group $group): void
    {
        if ($group->ownerCount() <= 1) {
            throw ValidationException::withMessages([
                'member' => __('A group must have at least one owner.'),
            ]);
        }
    }
}
