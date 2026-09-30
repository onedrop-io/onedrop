<?php

namespace App\Sandbox\Publishing;

use RuntimeException;

/**
 * The tailnet has a feature publishing needs turned off (Funnel for public links, HTTPS certificates for any);
 * publishing carries on once someone turns it on.
 */
class PublishNeedsFeature extends RuntimeException
{
    public const Funnel = 'funnel';

    public const Https = 'https';

    /**
     * @param  'funnel'|'https'  $feature
     */
    public function __construct(public readonly string $feature, public readonly string $url)
    {
        parent::__construct("Waiting for Tailscale {$feature} to be turned on.");
    }
}
