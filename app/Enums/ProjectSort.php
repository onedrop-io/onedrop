<?php

namespace App\Enums;

/** How the sidebar orders a user's pinned and recent projects (PRJ-010). */
enum ProjectSort: string
{
    /** Most recently updated first. */
    case Updated = 'updated';

    /** Newest first. */
    case Created = 'created';

    /** The order the user dragged them into; projects not placed yet come first, newest first. */
    case Manual = 'manual';
}
