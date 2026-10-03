<?php

namespace App\Concerns;

trait DescribesRealtime
{
    /**
     * Where the browser connects for live updates (LIVE-001), or null when the app doesn't broadcast (pages poll instead).
     * Read at runtime rather than built into the assets, so one build works at any address.
     *
     * @return array{key: string, host: string|null, port: int, scheme: string}|null
     */
    protected function realtime(): ?array
    {
        if (config('broadcasting.default') !== 'reverb' || ! config('broadcasting.connections.reverb.key')) {
            return null;
        }

        $browser = config('broadcasting.connections.reverb.browser');

        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => ($browser['host'] ?? null) ?: null,
            'port' => (int) $browser['port'],
            'scheme' => (string) $browser['scheme'],
        ];
    }
}
