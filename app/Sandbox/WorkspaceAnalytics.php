<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Custom analytics events. The app posts them to /__onedrop/event, docker/sandbox/host-proxy.mjs logs them to
 * .onedrop/events.log, and the agent describes them in .onedrop/analytics.json (following docker/sandbox/guides/analytics.md).
 */
class WorkspaceAnalytics
{
    public const EVENTS_LOG = '/workspace/.onedrop/events.log';

    public const CATALOG = '/workspace/.onedrop/analytics.json';

    public const GUIDE = '/opt/onedrop/guides/analytics.md';

    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Most events listed. */
    public const MAX_EVENTS = 50;

    /** Most common values listed per property. */
    public const TOP_VALUES = 5;

    public function __construct(protected SandboxProvider $provider, protected SandboxMonitoring $monitoring) {}

    /**
     * Each event with its count, visitors, the previous period's count, counts over time and its most
     * common property values: described events first (in the catalog's order), then the rest by count.
     *
     * @return array{set_up: bool, events: list<array{name: string, description: string|null, count: int, visitors: int, previous_count: int, over_time: list<array{t: int, value: int}>, props: list<array{key: string, values: list<array{label: string, count: int}>}>}>}
     *
     * @throws SandboxException
     */
    public function events(Sandbox $sandbox, string $range, bool $publishedOnly = false, ?int $now = null): array
    {
        [$window, $bucket] = SandboxGrowth::RANGES[$range];
        $now ??= time();
        $buckets = range(intdiv($now - $window, $bucket) * $bucket + $bucket, intdiv($now, $bucket) * $bucket, $bucket);

        $catalog = $this->catalog($sandbox);
        $events = [];
        $logged = false;
        $blank = fn (): array => ['count' => 0, 'visitors' => [], 'previous_count' => 0, 'over_time' => array_fill_keys($buckets, 0), 'props' => []];

        foreach ($catalog as $name => $description) {
            $events[$name] = $blank();
        }

        foreach ($this->monitoring->read($sandbox, self::EVENTS_LOG) as $entry) {
            $name = $entry['n'] ?? null;
            $at = intdiv((int) ($entry['t'] ?? 0), 1000);

            if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name)) {
                continue;
            }

            $logged = true;

            if ($at > $now || $at <= $now - 2 * $window || ($publishedOnly && ! ($entry['pub'] ?? false))) {
                continue;
            }

            $events[$name] ??= $blank();

            if ($at <= $now - $window) {
                $events[$name]['previous_count']++;

                continue;
            }

            $events[$name]['count']++;
            $events[$name]['visitors'][(string) ($entry['ip'] ?? '')] = true;
            $events[$name]['over_time'][intdiv($at, $bucket) * $bucket]++;

            foreach (is_array($entry['props'] ?? null) ? $entry['props'] : [] as $key => $value) {
                if (is_scalar($value) && preg_match(self::NAME_PATTERN, (string) $key)) {
                    $label = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                    $events[$name]['props'][$key][$label] = ($events[$name]['props'][$key][$label] ?? 0) + 1;
                }
            }
        }

        $order = array_flip(array_keys($catalog));
        uksort($events, fn (string $a, string $b) => [
            $order[$a] ?? PHP_INT_MAX, -$events[$a]['count'], $a,
        ] <=> [
            $order[$b] ?? PHP_INT_MAX, -$events[$b]['count'], $b,
        ]);

        $rows = [];

        foreach (array_slice($events, 0, self::MAX_EVENTS, true) as $name => $event) {
            $rows[] = [
                'name' => (string) $name,
                'description' => $catalog[$name] ?? null,
                'count' => $event['count'],
                'visitors' => count(array_filter(array_keys($event['visitors']), fn ($ip) => $ip !== '')),
                'previous_count' => $event['previous_count'],
                'over_time' => array_map(fn (int $t) => ['t' => $t, 'value' => $event['over_time'][$t]], $buckets),
                'props' => array_map(fn (string $key, array $values) => [
                    'key' => $key,
                    'values' => self::top($values),
                ], array_keys($event['props']), $event['props']),
            ];
        }

        return ['set_up' => $catalog !== [] || $logged, 'events' => $rows];
    }

    /**
     * Event name => description, from the agent's .onedrop/analytics.json; empty when there's none yet.
     *
     * @return array<string, string|null>
     *
     * @throws SandboxException
     */
    public function catalog(Sandbox $sandbox): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['sh', '-c', 'cat '.self::CATALOG.' 2>/dev/null']);
        $data = json_decode($result->output, true);
        $catalog = [];

        foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
            $name = is_array($event) ? ($event['name'] ?? null) : null;

            if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name) || array_key_exists($name, $catalog)) {
                continue;
            }

            $description = is_string($event['description'] ?? null) ? trim($event['description']) : '';
            $catalog[$name] = $description === '' ? null : mb_substr($description, 0, 300);
        }

        return array_slice($catalog, 0, self::MAX_EVENTS, true);
    }

    /**
     * The chat message asking the agent to add events, optionally the ones the user described.
     */
    public static function request(?string $events = null): string
    {
        $events = trim((string) $events);
        $events .= $events === '' || preg_match('/[.!?]$/', $events) ? '' : '.';
        $guide = 'Follow the guide at '.self::GUIDE.'.';

        return $events === ''
            ? "Add custom analytics events to my project. {$guide}"
            : "Add these custom analytics events to my project: {$events} {$guide}";
    }

    /**
     * The most common values, largest first (ties alphabetical).
     *
     * @param  array<string, int>  $counts
     * @return list<array{label: string, count: int}>
     */
    protected static function top(array $counts): array
    {
        $rows = array_map(fn ($label, int $count) => ['label' => (string) $label, 'count' => $count], array_keys($counts), $counts);
        usort($rows, fn (array $a, array $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return array_slice($rows, 0, self::TOP_VALUES);
    }
}
