<?php

namespace App\Enums;

/**
 * Where a project is published.
 */
enum PublishTarget: string
{
    /** Its own address on the server's domain, through the gateway (server installs only). */
    case Domain = 'domain';

    /** Its own node on the team's tailnet. */
    case Tailscale = 'tailscale';

    /** Deployed off the sandbox: a Fly machine, or Cloudflare for a front end, with its data in managed services (HOST-001). */
    case Hosting = 'hosting';
}
