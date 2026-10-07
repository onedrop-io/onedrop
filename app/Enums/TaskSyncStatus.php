<?php

namespace App\Enums;

/**
 * What's moving between a task's copy of the app and Main (TASK-003).
 */
enum TaskSyncStatus: string
{
    /** Making the task's copy from Main's sandbox. */
    case Forking = 'forking';

    /** Merging the task's work into Main. */
    case Applying = 'applying';

    /** Merging Main's newer work into the task. */
    case Updating = 'updating';

    /** Pushing a pull request's task to its branch on GitHub (GIT-014). */
    case Pushing = 'pushing';

    /** Merging commits pushed to a pull request since into its task (GIT-014). */
    case Pulling = 'pulling';
}
