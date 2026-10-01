<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Whether this role runs the organization: its members, groups, invites and every project in it.
     */
    public function manages(): bool
    {
        return $this !== self::Member;
    }
}
