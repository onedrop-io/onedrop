<?php

namespace App\Enums;

/**
 * What a project is: an app someone builds, or a person's own computer (CMP-001), which is a project nobody sees in
 * project lists.
 */
enum ProjectKind: string
{
    case App = 'app';
    case Computer = 'computer';
}
