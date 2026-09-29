<?php

namespace App\Enums;

enum PublishVisibility: string
{
    /** The team: people on its tailnet (Tailscale Serve), or signed in to OneDrop (the server's domain). */
    case Private = 'private';

    /** Anyone on the internet with the URL (Tailscale Funnel, or the server's domain). */
    case Public = 'public';
}
