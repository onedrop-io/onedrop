<?php

namespace App\Enums;

/**
 * What became of the sandbox a project moved off (SBX-013).
 */
enum OldSandboxStatus: string
{
    /** Deleted once the new sandbox had its files. */
    case Removed = 'removed';
    /** It didn't answer: kept, and checked on until it does. */
    case Waiting = 'waiting';
    /** It answered again with newer files, saved as a snapshot an admin can restore. */
    case Recovered = 'recovered';
    /** It never answered again. */
    case Gone = 'gone';
}
