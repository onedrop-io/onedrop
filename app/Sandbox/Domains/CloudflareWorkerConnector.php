<?php

namespace App\Sandbox\Domains;

use App\Models\ProjectDomain;
use App\Sandbox\Hosting\CloudflareApi;

/**
 * Custom domains on a hosted front end: a Workers custom domain on its site's Worker. The domain's DNS has to be in
 * the same Cloudflare account; Cloudflare then adds the record and the certificate itself.
 */
class CloudflareWorkerConnector implements Connector
{
    public function __construct(protected CloudflareApi $api, protected string $script) {}

    public function name(): string
    {
        return 'cloudflare-worker';
    }

    public function redirects(): bool
    {
        return false;
    }

    public function attach(ProjectDomain $domain): void
    {
        $zone = $this->api->zoneFor($domain->hostname)
            ?? throw new DomainException(__(':host isn\'t in the Cloudflare account this app is hosted in. Add the domain to that account (Cloudflare → Add a domain), then check again.', ['host' => $domain->hostname]));

        $domain->external_id = $this->api->addWorkerDomain($domain->hostname, $this->script, $zone);
        $domain->records = [];
    }

    public function check(ProjectDomain $domain): ?string
    {
        return null;
    }

    public function detach(ProjectDomain $domain): void
    {
        if ($domain->external_id) {
            $this->api->deleteWorkerDomain($domain->external_id);
        }
    }
}
