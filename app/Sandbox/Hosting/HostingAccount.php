<?php

namespace App\Sandbox\Hosting;

use App\Models\HostedService;

/**
 * The account at a hosting provider that something is made in: an organization's own (HOST-003) or the install's
 * (ADMIN-007), with the settings to reach it.
 */
final readonly class HostingAccount
{
    /**
     * @param  string  $owner  HostedService::OWNER_ORGANIZATION or HostedService::OWNER_PLATFORM
     * @param  array<string, mixed>  $settings
     */
    public function __construct(public string $provider, public string $owner, public array $settings) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return filled($this->settings[$key] ?? null) ? $this->settings[$key] : $default;
    }

    /**
     * Whether the organization's own account, rather than the install's.
     */
    public function isOrganizations(): bool
    {
        return $this->owner === HostedService::OWNER_ORGANIZATION;
    }
}
