<?php

namespace App\Enums;

/** How much of the app builder a user sees (PRJ-013). */
enum BuildMode: string
{
    /** Describe it and let the AI handle the technical side: no model picker, files, shell or git. */
    case Simple = 'simple';

    /** Everything: models, files, the shell and git. */
    case Advanced = 'advanced';
}
