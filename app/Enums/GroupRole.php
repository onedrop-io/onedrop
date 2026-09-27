<?php

namespace App\Enums;

enum GroupRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
