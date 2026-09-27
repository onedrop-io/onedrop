<?php

namespace App\Sandbox\Publishing;

use RuntimeException;

/**
 * The endpoint is waiting for someone to approve it in the browser.
 */
class PublishNeedsLogin extends RuntimeException
{
    public function __construct(public readonly string $loginUrl)
    {
        parent::__construct('Waiting for approval in Tailscale.');
    }
}
