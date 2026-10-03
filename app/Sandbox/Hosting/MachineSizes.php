<?php

namespace App\Sandbox\Hosting;

/**
 * The machine sizes a hosted app with a server can run on (HOST-010). Small is the install's default (its memory from
 * Settings → Hosting); the larger ones are Fly's performance CPUs, which Fly stops rather than suspends when idle (it
 * only suspends machines up to 2 GB), so their first visit after a quiet spell waits a few seconds longer.
 */
class MachineSizes
{
    public const DEFAULT = 'small';

    /**
     * @var array<string, array{label: string, cpu_kind: 'shared'|'performance', cpus: int, memory_mb: int|null}>
     */
    public const SIZES = [
        'small' => ['label' => 'Small: 1 shared CPU, 1 GB', 'cpu_kind' => 'shared', 'cpus' => 1, 'memory_mb' => null],
        'medium' => ['label' => 'Medium: 2 shared CPUs, 2 GB', 'cpu_kind' => 'shared', 'cpus' => 2, 'memory_mb' => 2048],
        'large' => ['label' => 'Large: 2 dedicated CPUs, 4 GB', 'cpu_kind' => 'performance', 'cpus' => 2, 'memory_mb' => 4096],
        'xlarge' => ['label' => 'Extra large: 4 dedicated CPUs, 8 GB', 'cpu_kind' => 'performance', 'cpus' => 4, 'memory_mb' => 8192],
    ];

    /**
     * The Fly guest for a size; Small's memory is the account's setting.
     *
     * @return array{cpu_kind: string, cpus: int, memory_mb: int}
     */
    public static function guest(?string $size, HostingAccount $account): array
    {
        $chosen = self::SIZES[$size ?? self::DEFAULT] ?? self::SIZES[self::DEFAULT];

        return [
            'cpu_kind' => $chosen['cpu_kind'],
            'cpus' => $chosen['cpus'],
            'memory_mb' => $chosen['memory_mb'] ?? (int) $account->get('memory_mb', 1024),
        ];
    }

    /**
     * Every size for the Publish panel.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => self::SIZES[$key]['label']], array_keys(self::SIZES));
    }
}
