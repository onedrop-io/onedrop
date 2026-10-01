<?php

namespace App\Enums;

enum PublishStatus: string
{
    case Publishing = 'publishing';
    case Live = 'live';
    case Failed = 'failed';

    /** Held for a platform admin's review before going public on the hosted install (PUB-003). */
    case Review = 'review';
}
