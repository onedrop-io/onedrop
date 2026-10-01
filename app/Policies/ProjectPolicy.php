<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): Response
    {
        return $this->ownsOrManages($user, $project);
    }

    /**
     * Determine whether the user can message the project's agent.
     */
    public function update(User $user, Project $project): Response
    {
        return $this->ownsOrManages($user, $project);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): Response
    {
        return $this->ownsOrManages($user, $project);
    }

    /**
     * Its owner while they're still in its organization, and the organization's owners and admins (ORG-005).
     * Platform admins get nothing here: another company's projects aren't theirs to open (ORG-006). Someone
     * outside its organization gets a 404, so they can't learn it exists (ORG-001).
     */
    protected function ownsOrManages(User $user, Project $project): Response
    {
        if (! $user->belongsToOrganization($project->organization_id)) {
            return Response::denyAsNotFound();
        }

        return $project->user_id === $user->id || $project->organization->isManagedBy($user)
            ? Response::allow()
            : Response::deny();
    }
}
