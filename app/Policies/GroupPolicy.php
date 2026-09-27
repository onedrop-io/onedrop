<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    /**
     * Site admins can do anything with groups.
     */
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    /**
     * Determine whether the user can view the group.
     */
    public function view(User $user, Group $group): bool
    {
        return $group->roleOf($user) !== null;
    }

    /**
     * Determine whether the user can update the group and manage its members.
     */
    public function update(User $user, Group $group): bool
    {
        return $group->isOwnedBy($user);
    }

    /**
     * Determine whether the user can delete the group.
     */
    public function delete(User $user, Group $group): bool
    {
        return $group->isOwnedBy($user);
    }
}
