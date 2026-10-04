<?php

namespace App\Sandbox\Domains;

use App\Models\ProjectDomain;
use App\Sandbox\Hosting\CloudflareApi;

/**
 * Custom domains through the gateway Worker (e.g. on Laravel Cloud): a Cloudflare for SaaS custom hostname on the
 * gateway domain's zone. The domain's CNAME points at the zone's fallback origin, Cloudflare validates and issues the
 * certificate, and the Worker (on the zone's catch-all route) serves it like the project's own address.
 */
class CloudflareSaasConnector implements Connector
{
    public function __construct(protected CloudflareApi $api, protected string $zoneId, protected string $target) {}

    public function name(): string
    {
        return 'cloudflare-saas';
    }

    public function redirects(): bool
    {
        return true;
    }

    public function attach(ProjectDomain $domain): void
    {
        $domain->external_id = $this->api->createCustomHostname($this->zoneId, $domain->hostname);
        $domain->records = [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => $this->target]];
    }

    public function check(ProjectDomain $domain): ?string
    {
        $state = $domain->external_id ? $this->api->customHostname($this->zoneId, $domain->external_id) : null;

        // Removed in Cloudflare meanwhile: add it again.
        if ($state === null) {
            $this->attach($domain);

            return $this->waiting($domain);
        }

        return $state['active'] ? null : ($state['problem'] ?? $this->waiting($domain));
    }

    protected function waiting(ProjectDomain $domain): string
    {
        return __('Waiting for :host to point at :target.', ['host' => $domain->hostname, 'target' => $this->target]);
    }

    public function detach(ProjectDomain $domain): void
    {
        if ($domain->external_id) {
            $this->api->deleteCustomHostname($this->zoneId, $domain->external_id);
        }
    }
}
