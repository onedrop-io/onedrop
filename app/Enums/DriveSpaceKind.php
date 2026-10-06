<?php

namespace App\Enums;

/**
 * Where a Drive item lives (DRIVE-001): a person's own My Drive, the organization's shared drive, or a group's.
 */
enum DriveSpaceKind: string
{
    case Personal = 'personal';
    case Organization = 'organization';
    case Group = 'group';
}
