<?php

namespace App\Enums;

/**
 * Where a project's custom domain is (DOM-001).
 */
enum DomainStatus: string
{
    /** Waiting for its DNS to point at the app, or for its certificate. */
    case Pending = 'pending';

    /** Serving the published app over HTTPS. */
    case Active = 'active';
}
