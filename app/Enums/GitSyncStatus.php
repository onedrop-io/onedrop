<?php

namespace App\Enums;

enum GitSyncStatus: string
{
    case Pushing = 'pushing';
    case Pulling = 'pulling';
    case Failed = 'failed';
}
