<?php

namespace App\Sandbox\Domains;

use App\Models\ProjectDomain;
use App\Sandbox\Hosting\FlyApi;

/**
 * Custom domains on a hosted app with a server: a Fly.io certificate on its Fly app. Fly says which records it wants
 * (a CNAME to the fly.dev address, or the app's A/AAAA addresses for a root domain).
 */
class FlyConnector implements Connector
{
    public function __construct(protected FlyApi $api, protected string $app) {}

    public function name(): string
    {
        return 'fly';
    }

    public function redirects(): bool
    {
        return false;
    }

    public function attach(ProjectDomain $domain): void
    {
        $this->api->addCertificate($this->app, $domain->hostname);
        $this->fillRecords($domain, $this->api->checkCertificate($this->app, $domain->hostname));
    }

    public function check(ProjectDomain $domain): ?string
    {
        $certificate = $this->api->checkCertificate($this->app, $domain->hostname);

        // Removed in Fly meanwhile: add it again.
        if ($certificate === null) {
            $this->attach($domain);

            return $this->waiting($domain);
        }

        $this->fillRecords($domain, $certificate);

        return $certificate['active'] ? null : ($certificate['problem'] ?? $this->waiting($domain));
    }

    protected function waiting(ProjectDomain $domain): string
    {
        return __('Waiting for :host\'s DNS.', ['host' => $domain->hostname]);
    }

    public function detach(ProjectDomain $domain): void
    {
        $this->api->deleteCertificate($this->app, $domain->hostname);
    }

    /**
     * @param  array{a: list<string>, aaaa: list<string>, cname: string|null}|null  $certificate
     */
    protected function fillRecords(ProjectDomain $domain, ?array $certificate): void
    {
        $cname = $certificate['cname'] ?? "{$this->app}.fly.dev";

        $domain->records = DomainDns::isApex($domain->hostname)
            ? [
                ...array_map(fn (string $ip): array => ['type' => 'A', 'name' => $domain->hostname, 'value' => $ip], $certificate['a'] ?? []),
                ...array_map(fn (string $ip): array => ['type' => 'AAAA', 'name' => $domain->hostname, 'value' => $ip], $certificate['aaaa'] ?? []),
            ]
            : [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => $cname]];
    }
}
