<?php

namespace App\Sandbox;

use App\Models\SystemSetting;
use Illuminate\Support\Arr;

/**
 * The sandbox providers an admin can turn on, configure and put in order (ADMIN-002): new projects run on the first
 * one that's on and set up. Their settings are saved in
 * the "sandboxes" SystemSetting and laid over config/sandbox.php by SystemConfig, so they win over `.env`.
 */
class SandboxProviders
{
    public const SETTING = 'sandboxes';

    /**
     * Each provider's name and the settings an admin can change. A "secret" is never sent back to the browser.
     *
     * @var array<string, array{label: string, description: string, required: list<string>, fields: array<string, array{label: string, type: 'text'|'number'|'secret'|'select', options?: list<string>, help?: string}>}>
     */
    public const PROVIDERS = [
        'docker' => [
            'label' => 'Docker',
            'description' => 'Containers on this server (or your computer).',
            'required' => [],
            'fields' => [
                'image' => ['label' => 'Image', 'type' => 'text'],
                'memory' => ['label' => 'Memory limit', 'type' => 'text', 'help' => 'Per sandbox, e.g. 2g or 1536m.'],
                'cpus' => ['label' => 'CPUs', 'type' => 'text', 'help' => 'Per sandbox, e.g. 2 or 1.5.'],
                'idle_seconds' => ['label' => 'Suspend after (seconds idle)', 'type' => 'number', 'help' => '0 never suspends.'],
                'stop_after_minutes' => ['label' => 'Stop after (minutes suspended)', 'type' => 'number', 'help' => '0 never stops.'],
                'runtime' => ['label' => 'Container runtime', 'type' => 'text', 'help' => 'Optional, e.g. runsc for gVisor.'],
                'nested_docker' => ['label' => 'Docker inside sandboxes', 'type' => 'select', 'options' => ['off', 'privileged', 'runtime'], 'help' => 'For projects with their own Docker Compose. Privileged is for local installs only; on a server, set a runtime such as sysbox-runc and pick runtime.'],
            ],
        ],
        'blaxel' => [
            'label' => 'Blaxel',
            'description' => 'MicroVMs on blaxel.ai.',
            'required' => ['api_key', 'workspace'],
            'fields' => [
                'api_key' => ['label' => 'API key', 'type' => 'secret'],
                'workspace' => ['label' => 'Workspace', 'type' => 'text'],
                'image' => ['label' => 'Image', 'type' => 'text'],
                'memory_mib' => ['label' => 'Memory (MB)', 'type' => 'number', 'help' => 'Also sets CPUs (one per 2048 MB).'],
                'region' => ['label' => 'Region', 'type' => 'text', 'help' => 'Empty picks the closest one.'],
            ],
        ],
        'runtime' => [
            'label' => 'Runtime Cloud',
            'description' => 'MicroVMs on withruntime.com.',
            'required' => ['api_key'],
            'fields' => [
                'api_key' => ['label' => 'API key', 'type' => 'secret'],
                'image' => ['label' => 'Image', 'type' => 'text'],
                'funding' => ['label' => 'Funding', 'type' => 'select', 'options' => ['trial', 'paid'], 'help' => 'Trial uses the free hours; paid uses the account\'s credit.'],
                'nested_docker' => ['label' => 'Docker inside sandboxes', 'type' => 'select', 'options' => ['off', 'on'], 'help' => 'For projects with their own Docker Compose. Give sandboxes enough memory and disk for their stack.'],
                'vcpu' => ['label' => 'vCPUs', 'type' => 'number'],
                'memory_mib' => ['label' => 'Memory (MB)', 'type' => 'number'],
                'disk_mib' => ['label' => 'Disk (MB)', 'type' => 'number'],
                'preview_visibility' => ['label' => 'Preview visibility', 'type' => 'select', 'options' => ['private', 'public'], 'help' => 'Public (paid only) lets previews show in the workspace.'],
            ],
        ],
        'e2b' => [
            'label' => 'E2B',
            'description' => 'MicroVMs on e2b.dev, with Docker inside.',
            'required' => ['api_key'],
            'fields' => [
                'api_key' => ['label' => 'API key', 'type' => 'secret'],
                'nested_docker' => ['label' => 'Docker inside sandboxes', 'type' => 'select', 'options' => ['on', 'off'], 'help' => 'For projects with their own Docker Compose.'],
                'vcpu' => ['label' => 'vCPUs', 'type' => 'number', 'help' => 'Up to 8. Changing the size builds the image again.'],
                'memory_mib' => ['label' => 'Memory (MB)', 'type' => 'number', 'help' => 'Up to 8192.'],
                'disk_mib' => ['label' => 'Free disk (MB)', 'type' => 'number'],
                'idle_seconds' => ['label' => 'Pause after (seconds idle)', 'type' => 'number', 'help' => 'Memory is kept; the next visit wakes it in about a second.'],
                'image' => ['label' => 'Template', 'type' => 'text', 'help' => 'Built by OneDrop from the published sandbox image.'],
            ],
        ],
    ];

    /**
     * The provider new projects run on.
     */
    public function active(): string
    {
        return (string) config('sandbox.provider');
    }

    /**
     * Every provider, in the admin's order: the saved one, or (never saved) the active one first.
     *
     * @return list<string>
     */
    public function order(): array
    {
        $saved = SystemSetting::group(self::SETTING)['order'] ?? null;

        return array_values(array_unique([
            ...(is_array($saved) ? array_intersect($saved, array_keys(self::PROVIDERS)) : $this->activeIfReal()),
            ...array_keys(self::PROVIDERS),
        ]));
    }

    /**
     * Providers that are turned on, in order: the saved list, or (never saved) the active one and any with the
     * settings it needs.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $saved = SystemSetting::group(self::SETTING)['enabled'] ?? null;

        return array_values(array_filter($this->order(), fn (string $name): bool => is_array($saved)
            ? in_array($name, $saved, true)
            : $name === $this->active() || ($name !== 'docker' && $this->missing($name) === [])));
    }

    /**
     * The first provider that's turned on and has the settings it needs: where new projects run.
     */
    public function first(): ?string
    {
        return Arr::first($this->enabled(), fn (string $name): bool => $this->missing($name) === []);
    }

    /**
     * Whether a provider's sandboxes (the active one's by default) get Docker inside them, for projects that run their
     * own Docker Compose (SBX-008). Each provider has its own setting; Blaxel can't.
     */
    public function runsDocker(?string $name = null): bool
    {
        $name ??= $this->active();
        $setting = config("sandbox.providers.{$name}.nested_docker");

        return match ($name) {
            'docker' => in_array($setting, ['privileged', 'runtime'], true),
            'runtime', 'e2b' => $setting === 'on',
            default => false,
        };
    }

    /**
     * The required settings a provider doesn't have yet.
     *
     * @return list<string>
     */
    public function missing(string $name): array
    {
        return array_values(array_filter(
            self::PROVIDERS[$name]['required'],
            fn (string $key): bool => blank(config("sandbox.providers.{$name}.{$key}")),
        ));
    }

    /**
     * Turn a provider on or off, and save its settings. Blank secrets and numbers keep the saved (or `.env`) value.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(string $name, bool $enabled, array $values): void
    {
        $settings = SystemSetting::group(self::SETTING);
        $saved = $settings['providers'][$name] ?? [];

        foreach (self::PROVIDERS[$name]['fields'] as $key => $field) {
            if (! array_key_exists($key, $values) || (in_array($field['type'], ['secret', 'number'], true) && blank($values[$key]))) {
                continue;
            }

            $saved[$key] = match ($field['type']) {
                'number' => (int) $values[$key],
                default => blank($values[$key]) ? null : (string) $values[$key],
            };
        }

        $settings['providers'][$name] = $saved;
        $settings['enabled'] = array_values(array_intersect($this->order(), $enabled
            ? [...$this->enabled(), $name]
            : array_diff($this->enabled(), [$name])));

        SystemSetting::put(self::SETTING, $settings);
        SystemConfig::saved();
    }

    /**
     * Cap how many task copies one project runs at once (TASK-003), or null for no limit.
     */
    public function limitTaskCopies(?int $limit): void
    {
        SystemSetting::merge(self::SETTING, ['max_task_copies' => $limit]);
        SystemConfig::saved();
    }

    /**
     * Put the providers in this order; new projects run on the first one that's on and set up.
     *
     * @param  list<string>  $order
     */
    public function reorder(array $order): void
    {
        SystemSetting::merge(self::SETTING, ['order' => $order]);
        SystemConfig::saved();
    }

    /**
     * Whether turning this provider off would leave no provider that's on and set up.
     */
    public function isLastUsable(string $name): bool
    {
        return array_filter($this->enabled(), fn (string $other): bool => $other !== $name && $this->missing($other) === []) === [];
    }

    /**
     * Every provider for the admin page, in order: its settings (secrets only as "set" or not), whether it's on,
     * active, missing anything, and how many sandboxes it holds.
     *
     * @param  array<string, int>  $counts  provider => sandboxes
     * @return list<array<string, mixed>>
     */
    public function describe(array $counts): array
    {
        $enabled = $this->enabled();

        return array_map(function (string $name) use ($enabled, $counts): array {
            $provider = self::PROVIDERS[$name];
            $config = config("sandbox.providers.{$name}", []);

            return [
                'name' => $name,
                'label' => $provider['label'],
                'description' => $provider['description'],
                'enabled' => in_array($name, $enabled, true),
                'active' => $name === $this->active(),
                'missing' => $this->missing($name),
                'sandboxes' => $counts[$name] ?? 0,
                'fields' => array_values(Arr::map($provider['fields'], fn (array $field, string $key): array => [
                    'key' => $key,
                    ...$field,
                    'value' => $field['type'] === 'secret' ? null : ($config[$key] ?? null),
                    'set' => filled($config[$key] ?? null),
                ])),
            ];
        }, $this->order());
    }

    /**
     * The active provider, unless it's the test suite's fake one.
     *
     * @return list<string>
     */
    protected function activeIfReal(): array
    {
        return isset(self::PROVIDERS[$this->active()]) ? [$this->active()] : [];
    }
}
