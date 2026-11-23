<?php

namespace App\Sandbox\Domains;

use App\Models\ProjectDomain;
use App\Sandbox\Hosting\VercelApi;

/**
 * Custom domains on an app hosted on Vercel (HOST-011): the domain added to its Vercel project, which issues the
 * certificate once the DNS points at Vercel (a CNAME, or A records for a root domain, as Vercel recommends).
 */
class VercelConnector implements Connector
{
    public function __construct(protected VercelApi $api, protected string $project) {}

    public function name(): string
    {
        return 'vercel';
    }

    public function redirects(): bool
    {
        return false;
    }

    public function attach(ProjectDomain $domain): void
    {
        $this->api->addDomain($this->project, $domain->hostname);
        $this->fillRecords($domain, $this->api->domain($this->project, $domain->hostname));
    }

    public function check(ProjectDomain $domain): ?string
    {
        $status = $this->api->domain($this->project, $domain->hostname);

        // Removed in Vercel meanwhile: add it again.
        if ($status === null) {
            $this->attach($domain);

            return $this->waiting($domain);
        }

        $this->fillRecords($domain, $status);

        return $status['verified'] && ! $status['misconfigured'] ? null : $this->waiting($domain);
    }

    public function detach(ProjectDomain $domain): void
    {
        $this->api->removeDomain($this->project, $domain->hostname);
    }

    protected function waiting(ProjectDomain $domain): string
    {
        return __('Waiting for :host\'s DNS.', ['host' => $domain->hostname]);
    }

    /**
     * @param  array{verified: bool, misconfigured: bool, a: list<string>, cname: string|null}|null  $status
     */
    protected function fillRecords(ProjectDomain $domain, ?array $status): void
    {
        $domain->records = DomainDns::isApex($domain->hostname)
            ? array_map(fn (string $ip): array => ['type' => 'A', 'name' => $domain->hostname, 'value' => $ip], $status['a'] ?? ['76.76.21.21'])
            : [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => $status['cname'] ?? 'cname.vercel-dns.com']];
    }
}
