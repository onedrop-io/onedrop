<?php

namespace App\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /** A short status line from the agent, e.g. "Planning app development". */
    case Activity = 'activity';
}
