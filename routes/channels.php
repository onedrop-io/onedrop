<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// A project's live updates (LIVE-001): for whoever can see the project.
Broadcast::channel('project.{project}', fn (User $user, Project $project): bool => $user->can('view', $project));
