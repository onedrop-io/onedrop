<?php

namespace App\Policies;

use App\Models\Skill;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SkillPolicy
{
    /**
     * Its owner, and everyone in its organization when it's shared, can read it and turn it on.
     */
    public function view(User $user, Skill $skill): Response
    {
        return $this->inOrganization($user, $skill)
            ?? ($skill->isVisibleTo($user) || $skill->organization->isManagedBy($user) ? Response::allow() : Response::deny());
    }

    /**
     * Only its owner (or an admin of its organization) can change it.
     */
    public function update(User $user, Skill $skill): Response
    {
        return $this->inOrganization($user, $skill) ?? $this->ownsOrManages($user, $skill);
    }

    /**
     * Only its owner (or an admin of its organization) can delete it.
     */
    public function delete(User $user, Skill $skill): Response
    {
        return $this->inOrganization($user, $skill) ?? $this->ownsOrManages($user, $skill);
    }

    /**
     * A 404 for anyone outside its organization, so they can't learn it exists (ORG-001).
     */
    protected function inOrganization(User $user, Skill $skill): ?Response
    {
        return $user->belongsToOrganization($skill->organization_id) ? null : Response::denyAsNotFound();
    }

    protected function ownsOrManages(User $user, Skill $skill): Response
    {
        return $skill->user_id === $user->id || $skill->organization->isManagedBy($user) ? Response::allow() : Response::deny();
    }
}
