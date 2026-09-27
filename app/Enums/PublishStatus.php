<?php

namespace App\Enums;

enum PublishStatus: string
{
    case Publishing = 'publishing';
    case Live = 'live';
    case Failed = 'failed';
}
