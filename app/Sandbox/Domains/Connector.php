<?php

namespace App\Sandbox\Domains;

use App\Models\ProjectDomain;
use App\Sandbox\Hosting\HostingException;

/**
 * Serves a project's custom domains where it's published (DOM-001): through the gateway (Caddy, or the Worker's
 * Cloudflare for SaaS zone), or at the hosting provider (Fly.io certificates, Workers custom domains).
 */
interface Connector
{
    /**
     * Stored as the domain's "via".
     */
    public function name(): string;

    /**
     * Whether something in front of the app can send other hosts to the primary domain (DOM-002).
     */
    public function redirects(): bool;

    /**
     * Connect the domain here, filling in its external_id and the DNS records to add (not saved).
     *
     * @throws DomainException|HostingException
     */
    public function attach(ProjectDomain $domain): void;

    /**
     * Null once the domain serves the app over HTTPS, otherwise what it's waiting for. May refresh its records.
     *
     * @throws DomainException|HostingException
     */
    public function check(ProjectDomain $domain): ?string;

    /**
     * Stop serving the domain here. Removing one that's gone is not an error.
     *
     * @throws HostingException
     */
    public function detach(ProjectDomain $domain): void;
}
