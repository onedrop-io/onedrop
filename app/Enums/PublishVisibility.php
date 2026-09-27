<?php

namespace App\Enums;

enum PublishVisibility: string
{
    /** People on the team's tailnet (Tailscale Serve). */
    case Private = 'private';

    /** Anyone on the internet with the URL (Tailscale Funnel). */
    case Public = 'public';
}
