<?php

namespace App\Enums;

/**
 * Where a project's abuse check is at on the hosted install (PUB-003, ADMIN-006).
 */
enum AbuseReviewStatus: string
{
    /** The last check found nothing. */
    case Clear = 'clear';

    /** Jev flagged it: public publishing and its share page wait for a platform admin. */
    case Held = 'held';

    /** A platform admin looked and let it through. */
    case Approved = 'approved';

    /** A platform admin took it down: it can't be published publicly or shared until one approves it. */
    case TakenDown = 'taken_down';

    /**
     * Whether the project's public app and share page are kept offline.
     */
    public function blocksPublic(): bool
    {
        return $this === self::Held || $this === self::TakenDown;
    }
}
