<?php

namespace App\Enums;

/**
 * Where a moved sandbox's files came from (SBX-005, SBX-013).
 */
enum MoveSource: string
{
    /** A snapshot of the old sandbox taken for the move. */
    case Fresh = 'fresh';
    /** An earlier snapshot: the old sandbox didn't answer, or the admin picked one. */
    case Snapshot = 'snapshot';
    /** Copied through the platform (a local snapshot disk and a remote sandbox). */
    case Copy = 'copy';
    /** Only the code, from its git backup (SBX-006). */
    case Backup = 'backup';
    /** Nothing kept. */
    case None = 'none';
}
