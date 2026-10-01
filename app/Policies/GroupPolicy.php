<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    /**
     * Determine whether the user can view the group: its members and its organization's admins.
     */
    public function view(User $user, Group $group): bool
    {
        return ($group->roleOf($user) !== null && $user->belongsToOrganization($group->organization_id))
            || $group->organization->isManagedBy($user);
    }

    /**
     * Determine whether the user can update the group and manage its members.
     */
    public function update(User $user, Group $group): bool
    {
        return ($group->isOwnedBy($user) && $user->belongsToOrganization($group->organization_id))
            || $group->organization->isManagedBy($user);
    }

    /**
     * Determine whether the user can delete the group.
     */
    public function delete(User $user, Group $group): bool
    {
        return $this->update($user, $group);
    }
}
