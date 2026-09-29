<?php

namespace App\Enums;

/**
 * Where a share page's preview card is at (SHARE-001).
 */
enum ShareCardStatus: string
{
    case Capturing = 'capturing';
    case Ready = 'ready';
    case Failed = 'failed';
}
