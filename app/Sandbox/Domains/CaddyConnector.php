<?php

namespace App\Sandbox\Domains;

use App\Models\ProjectDomain;

/**
 * Custom domains on a server install: the domain points at the server, and Caddy gets its certificate on the first
 * visit (on-demand TLS, approved by SandboxGatewayController::certificate for domains a project added).
 */
class CaddyConnector implements Connector
{
    public function __construct(protected string $gatewayDomain, protected DomainDns $dns) {}

    public function name(): string
    {
        return 'caddy';
    }

    public function redirects(): bool
    {
        return true;
    }

    public function attach(ProjectDomain $domain): void
    {
        if (! DomainDns::isApex($domain->hostname)) {
            $domain->records = [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => $this->gatewayDomain]];

            return;
        }

        $domain->records = array_map(fn (string $address): array => [
            'type' => str_contains($address, ':') ? 'AAAA' : 'A',
            'name' => $domain->hostname,
            'value' => $address,
        ], $this->dns->addresses($this->gatewayDomain));
    }

    public function check(ProjectDomain $domain): ?string
    {
        $server = $this->dns->addresses($this->gatewayDomain);
        $current = $this->dns->addresses($domain->hostname);

        if ($current === []) {
            return __(':host doesn\'t point anywhere yet. Add the record below; DNS changes can take a while to show up.', ['host' => $domain->hostname]);
        }

        if (array_intersect($current, $server) === []) {
            return __(':host points at :current, not this server (:server).', [
                'host' => $domain->hostname,
                'current' => implode(', ', $current),
                'server' => implode(', ', $server),
            ]);
        }

        return null;
    }

    public function detach(ProjectDomain $domain): void {}
}
