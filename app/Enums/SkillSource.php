<?php

namespace App\Enums;

/**
 * Where one of a user's agent skills came from (SKILL-002, SKILL-003).
 */
enum SkillSource: string
{
    case Written = 'written';
    case GitHub = 'github';
    case Upload = 'upload';
    case Project = 'project';
}
