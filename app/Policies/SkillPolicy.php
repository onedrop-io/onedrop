<?php

namespace App\Policies;

use App\Models\Skill;
use App\Models\User;

class SkillPolicy
{
    /**
     * Site admins can do anything with skills.
     */
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    /**
     * Its owner, and everyone when it's shared, can read it and turn it on.
     */
    public function view(User $user, Skill $skill): bool
    {
        return $skill->isVisibleTo($user);
    }

    /**
     * Only its owner can change it.
     */
    public function update(User $user, Skill $skill): bool
    {
        return $skill->user_id === $user->id;
    }

    /**
     * Only its owner can delete it.
     */
    public function delete(User $user, Skill $skill): bool
    {
        return $skill->user_id === $user->id;
    }
}
