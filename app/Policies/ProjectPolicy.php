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
     * Determine whether the user can see the app on its organization's Apps page and load its icon (APPS-001):
     * anyone in the organization while it's listed there, and the people who can open the project.
     */
    public function openApp(User $user, Project $project): Response
    {
        if ($user->belongsToOrganization($project->organization_id) && $project->listedInApps()) {
            return Response::allow();
        }

        return $this->ownsOrManages($user, $project);
    }

    /**
     * Determine whether the user can feature the app on its organization's Apps page: its owners and admins (APPS-003).
     */
    public function feature(User $user, Project $project): Response
    {
        if (! $user->belongsToOrganization($project->organization_id)) {
            return Response::denyAsNotFound();
        }

        return $project->organization->isManagedBy($user) ? Response::allow() : Response::deny();
    }

    /**
     * Its owner while they're still in its organization, and the organization's owners and admins (ORG-005); only its
     * owner for a computer (CMP-001).
     * Platform admins get nothing here: another company's projects aren't theirs to open (ORG-006). Someone
     * outside its organization gets a 404, so they can't learn it exists (ORG-001).
     */
    protected function ownsOrManages(User $user, Project $project): Response
    {
        if (! $user->belongsToOrganization($project->organization_id)) {
            return Response::denyAsNotFound();
        }

        // A person's computer holds their browser's sign-ins and their own files: only theirs to open (CMP-001).
        if ($project->isComputer()) {
            return $project->user_id === $user->id && $project->organization->computersEnabled() ? Response::allow() : Response::denyAsNotFound();
        }

        return $project->user_id === $user->id || $project->organization->isManagedBy($user)
            ? Response::allow()
            : Response::deny();
    }
}
