<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// A project's live updates (LIVE-001): for whoever can see the project.
Broadcast::channel('project.{project}', fn (User $user, Project $project): bool => $user->can('view', $project));

// Drive's changes (DRIVE-001): its shared places for the organization's people, a person's My Drive for them alone.
Broadcast::channel('drive.{organization}', fn (User $user, int $organization): bool => $user->belongsToOrganization($organization));
Broadcast::channel('drive.{organization}.user.{id}', fn (User $user, int $organization, int $id): bool => $user->id === $id && $user->belongsToOrganization($organization));
