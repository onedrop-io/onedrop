<?php

namespace App\Sandbox\Hosting;

use App\Models\HostedService;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Sandbox\SystemConfig;
use Illuminate\Support\Arr;

/**
 * The hosting providers published apps can run on (HOST-001..003, ADMIN-007), one for each role: Fly.io runs apps with
 * a server (and their volumes), Cloudflare serves front ends and holds R2 buckets, Neon has Postgres and Upstash Redis.
 * The install's accounts are admin settings, saved in the "hosting" SystemSetting and laid over config/hosting.php by
 * SystemConfig; an organization can connect its own account for any of them, which is used instead.
 */
class HostingProviders
{
    public const SETTING = 'hosting';

    /**
     * Each provider, what it's used for, and its settings. A "secret" is never sent back to the browser; an "account"
     * setting is what an organization enters to connect its own account, the rest are the install's.
     *
     * @var array<string, array{label: string, description: string, roles: list<string>, required: list<string>, fields: array<string, array{label: string, type: 'text'|'number'|'secret', account?: bool, help?: string}>}>
     */
    public const PROVIDERS = [
        'fly' => [
            'label' => 'Fly.io',
            'description' => 'Runs apps that have a server, with a volume for data that has no managed service.',
            'roles' => ['server', 'volume'],
            'required' => ['api_token', 'org_slug'],
            'fields' => [
                'api_token' => ['label' => 'API token', 'type' => 'secret', 'account' => true, 'help' => 'An organization token: fly tokens create org.'],
                'org_slug' => ['label' => 'Organization', 'type' => 'text', 'account' => true, 'help' => 'Its slug, e.g. personal.'],
                'region' => ['label' => 'Region', 'type' => 'text', 'account' => true, 'help' => 'Where apps and volumes go, e.g. iad.'],
                'base_image' => ['label' => 'Base image', 'type' => 'text', 'help' => 'The sandbox image apps run on. Fly must be able to pull it.'],
                'memory_mb' => ['label' => 'Memory (MB)', 'type' => 'number', 'help' => 'Per app.'],
                'volume_gb' => ['label' => 'Volume size (GB)', 'type' => 'number', 'help' => 'For apps that keep data on disk.'],
            ],
        ],
        'cloudflare' => [
            'label' => 'Cloudflare',
            'description' => 'Serves front ends (Workers static assets) and holds file storage for apps that use S3 (R2).',
            'roles' => ['static', 'bucket'],
            'required' => ['account_id', 'api_token'],
            'fields' => [
                'account_id' => ['label' => 'Account ID', 'type' => 'text', 'account' => true],
                'api_token' => ['label' => 'API token', 'type' => 'secret', 'account' => true, 'help' => 'With Workers Scripts, Workers R2 Storage and Account API Tokens edit permissions.'],
            ],
        ],
        'neon' => [
            'label' => 'Neon',
            'description' => 'Postgres for apps that use it, one Neon project each.',
            'roles' => ['postgres'],
            'required' => ['api_key'],
            'fields' => [
                'api_key' => ['label' => 'API key', 'type' => 'secret', 'account' => true],
                'org_id' => ['label' => 'Organization ID', 'type' => 'text', 'account' => true, 'help' => 'Needed for an organization API key.'],
                'region' => ['label' => 'Region', 'type' => 'text', 'account' => true, 'help' => 'e.g. aws-us-east-1.'],
            ],
        ],
        'upstash' => [
            'label' => 'Upstash',
            'description' => 'Redis for apps that use it.',
            'roles' => ['redis'],
            'required' => ['email', 'api_key'],
            'fields' => [
                'email' => ['label' => 'Account email', 'type' => 'text', 'account' => true],
                'api_key' => ['label' => 'API key', 'type' => 'secret', 'account' => true],
                'region' => ['label' => 'Region', 'type' => 'text', 'account' => true, 'help' => 'e.g. us-east-1.'],
            ],
        ],
    ];

    /**
     * The provider that does a role: server, volume, static, bucket, postgres or redis.
     */
    public function providerFor(string $role): string
    {
        foreach (self::PROVIDERS as $name => $provider) {
            if (in_array($role, $provider['roles'], true)) {
                return $name;
            }
        }

        throw new HostingException("Nothing hosts {$role}.");
    }

    /**
     * The account to make something new in: the organization's own when it connected one, otherwise the install's when
     * an admin turned it on and set it up; null when there's neither.
     */
    public function account(?Organization $organization, string $provider): ?HostingAccount
    {
        return ($organization ? $this->organizationAccount($organization, $provider) : null) ?? $this->platformAccount($provider);
    }

    /**
     * The account something was made in, or null when it's gone (an organization disconnected, an admin removed a key).
     */
    public function accountFor(HostedService $service): ?HostingAccount
    {
        return $service->owner === HostedService::OWNER_ORGANIZATION
            ? $this->organizationAccount($service->project->organization, $service->provider)
            : $this->platformAccount($service->provider, requireEnabled: false);
    }

    /**
     * The install's account for a provider, when it's turned on (or, for something already made in it, at least still
     * set up) and has what it needs.
     */
    public function platformAccount(string $provider, bool $requireEnabled = true): ?HostingAccount
    {
        $settings = $this->platformSettings($provider);

        if (($requireEnabled && ! $settings['enabled']) || $this->missing($provider, $settings) !== []) {
            return null;
        }

        return new HostingAccount($provider, HostedService::OWNER_PLATFORM, $settings);
    }

    /**
     * The organization's own account for a provider, with the install's other settings (e.g. the base image).
     */
    public function organizationAccount(Organization $organization, string $provider): ?HostingAccount
    {
        $own = $organization->hosting_accounts[$provider] ?? null;

        if (! is_array($own) || $this->missing($provider, $own) !== []) {
            return null;
        }

        $installs = Arr::except($this->platformSettings($provider), $this->accountFields($provider));

        return new HostingAccount($provider, HostedService::OWNER_ORGANIZATION, [...$installs, ...array_filter($own, 'filled')]);
    }

    /**
     * The required settings missing from these.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function missing(string $provider, array $settings): array
    {
        return array_values(array_filter(self::PROVIDERS[$provider]['required'], fn (string $key): bool => blank($settings[$key] ?? null)));
    }

    /**
     * Whether projects in this organization can be hosted at all: something runs apps with a server or front ends.
     */
    public function available(?Organization $organization): bool
    {
        return $this->account($organization, 'fly') !== null || $this->account($organization, 'cloudflare') !== null;
    }

    /**
     * Turn one of the install's providers on or off, and save its settings. Blank secrets and numbers keep the saved (or
     * `.env`) value.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(string $provider, bool $enabled, array $values): void
    {
        $settings = SystemSetting::group(self::SETTING);

        $settings['providers'][$provider] = [
            ...$this->merge($provider, $settings['providers'][$provider] ?? [], $values, array_keys(self::PROVIDERS[$provider]['fields'])),
            'enabled' => $enabled,
        ];

        SystemSetting::put(self::SETTING, $settings);
        SystemConfig::saved();
    }

    /**
     * Connect (or change) the organization's own account for a provider (HOST-003). A blank secret keeps the saved one.
     *
     * @param  array<string, mixed>  $values
     */
    public function connect(Organization $organization, string $provider, array $values): void
    {
        $accounts = $organization->hosting_accounts ?? [];
        $accounts[$provider] = $this->merge($provider, $accounts[$provider] ?? [], $values, $this->accountFields($provider));

        $organization->forceFill(['hosting_accounts' => $accounts])->save();
    }

    /**
     * Forget the organization's own account for a provider. Nothing in it is deleted.
     */
    public function disconnect(Organization $organization, string $provider): void
    {
        $accounts = $organization->hosting_accounts ?? [];
        unset($accounts[$provider]);

        $organization->forceFill(['hosting_accounts' => $accounts === [] ? null : $accounts])->save();
    }

    /**
     * Every provider for the admin page: the install's settings (secrets only as "set" or not), whether it's on, what
     * it still needs, and how many things are made in its account.
     *
     * @param  array<string, int>  $counts  provider => hosted services in the install's account
     * @return list<array<string, mixed>>
     */
    public function describe(array $counts): array
    {
        return array_map(function (string $name) use ($counts): array {
            $settings = $this->platformSettings($name);

            return [
                ...$this->summary($name),
                'enabled' => $settings['enabled'],
                'missing' => $this->missing($name, $settings),
                'services' => $counts[$name] ?? 0,
                'fields' => $this->fields($name, array_keys(self::PROVIDERS[$name]['fields']), $settings),
            ];
        }, array_keys(self::PROVIDERS));
    }

    /**
     * Every provider for an organization's settings: whether it connected its own account, and whether the install's
     * is there to fall back on.
     *
     * @param  array<string, int>  $counts  provider => hosted services in the organization's own account
     * @return list<array<string, mixed>>
     */
    public function describeFor(Organization $organization, array $counts): array
    {
        return array_map(function (string $name) use ($organization, $counts): array {
            $own = $organization->hosting_accounts[$name] ?? null;

            return [
                ...$this->summary($name),
                'connected' => is_array($own),
                'missing' => is_array($own) ? $this->missing($name, $own) : [],
                'platform' => $this->platformAccount($name) !== null,
                'services' => $counts[$name] ?? 0,
                'fields' => $this->fields($name, $this->accountFields($name), is_array($own) ? $own : []),
            ];
        }, array_keys(self::PROVIDERS));
    }

    /**
     * The install's settings for a provider: config, with what an admin saved laid over it by SystemConfig.
     *
     * @return array<string, mixed>
     */
    protected function platformSettings(string $provider): array
    {
        $config = config("hosting.providers.{$provider}", []);

        return [...$config, 'enabled' => (bool) ($config['enabled'] ?? false)];
    }

    /**
     * @return list<string>
     */
    protected function accountFields(string $provider): array
    {
        return array_keys(array_filter(self::PROVIDERS[$provider]['fields'], fn (array $field): bool => $field['account'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function merge(string $provider, array $saved, array $values, array $keys): array
    {
        foreach ($keys as $key) {
            $field = self::PROVIDERS[$provider]['fields'][$key];

            if (! array_key_exists($key, $values) || (in_array($field['type'], ['secret', 'number'], true) && blank($values[$key]))) {
                continue;
            }

            $saved[$key] = match ($field['type']) {
                'number' => (int) $values[$key],
                default => blank($values[$key]) ? null : trim((string) $values[$key]),
            };
        }

        return $saved;
    }

    /**
     * @return array{name: string, label: string, description: string, roles: list<string>}
     */
    protected function summary(string $name): array
    {
        $provider = self::PROVIDERS[$name];

        return ['name' => $name, 'label' => $provider['label'], 'description' => $provider['description'], 'roles' => $provider['roles']];
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    protected function fields(string $provider, array $keys, array $values): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            ...Arr::except(self::PROVIDERS[$provider]['fields'][$key], ['account']),
            'value' => self::PROVIDERS[$provider]['fields'][$key]['type'] === 'secret' ? null : ($values[$key] ?? null),
            'set' => filled($values[$key] ?? null),
        ], $keys);
    }
}
