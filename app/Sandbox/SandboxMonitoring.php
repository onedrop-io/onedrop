<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Turns the sandbox's own request log and resource samples (written by
 * docker/sandbox/host-proxy.mjs into /workspace/.zap) into chart-ready series.
 */
class SandboxMonitoring
{
    public const ACCESS_LOG = '/workspace/.zap/access.log';

    public const METRICS_LOG = '/workspace/.zap/metrics.log';

    /** Most log bytes read per request (newest kept). */
    public const MAX_BYTES = 8_000_000;

    /**
     * Range => [window seconds, application bucket seconds, infrastructure bucket seconds].
     *
     * @var array<string, array{int, int, int}>
     */
    public const RANGES = [
        '1h' => [3600, 60, 60],
        '24h' => [86400, 3600, 900],
        '7d' => [604800, 7200, 7200],
    ];

    /** Request-duration histogram bins: [label, upper bound in ms (exclusive)]. */
    public const DURATION_BINS = [['< 50', 50], ['< 150', 150], ['< 300', 300], ['< 500', 500], ['< 1000', 1000], ['1000+', PHP_INT_MAX]];

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Requests, unique visitors, status classes over time, and a duration histogram.
     *
     * @return array{range: string, bucket_seconds: int, total: int, unique_ips: int, error_rate: float|null, requests: list<array{t: int, count: int}>, statuses: list<array{t: int, 2xx: int, 3xx: int, 4xx: int, 5xx: int}>, durations: list<array{label: string, count: int}>}
     *
     * @throws SandboxException
     */
    public function application(Sandbox $sandbox, string $range, bool $publishedOnly = false, ?int $now = null): array
    {
        [$window, $bucket] = self::RANGES[$range];
        $now ??= time();
        $buckets = $this->buckets($now, $window, $bucket);

        $requests = array_fill_keys($buckets, 0);
        $statuses = array_fill_keys($buckets, ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0]);
        $durations = array_fill(0, count(self::DURATION_BINS), 0);
        $ips = [];
        $errors = 0;
        $total = 0;

        foreach ($this->read($sandbox, self::ACCESS_LOG) as $entry) {
            $at = intdiv((int) ($entry['t'] ?? 0), 1000);

            if ($at <= $now - $window || $at > $now || ($publishedOnly && ! ($entry['pub'] ?? false))) {
                continue;
            }

            $key = intdiv($at, $bucket) * $bucket;

            if (! isset($requests[$key])) {
                continue;
            }

            $status = (int) ($entry['s'] ?? 0);
            $class = match (true) {
                $status >= 500 => '5xx',
                $status >= 400 => '4xx',
                $status >= 300 => '3xx',
                default => '2xx',
            };

            $total++;
            $requests[$key]++;
            $statuses[$key][$class]++;
            $errors += $status >= 500 ? 1 : 0;
            $ips[(string) ($entry['ip'] ?? '')] = true;

            $duration = (int) ($entry['d'] ?? 0);
            foreach (self::DURATION_BINS as $index => [, $limit]) {
                if ($duration < $limit) {
                    $durations[$index]++;
                    break;
                }
            }
        }

        return [
            'range' => $range,
            'bucket_seconds' => $bucket,
            'total' => $total,
            'unique_ips' => count(array_filter(array_keys($ips), fn (string $ip) => $ip !== '')),
            'error_rate' => $total > 0 ? round($errors / $total, 4) : null,
            'requests' => array_map(fn (int $t) => ['t' => $t, 'count' => $requests[$t]], $buckets),
            'statuses' => array_map(fn (int $t) => ['t' => $t, ...$statuses[$t]], $buckets),
            'durations' => array_map(fn (array $bin, int $count) => ['label' => $bin[0], 'count' => $count], self::DURATION_BINS, $durations),
        ];
    }

    /**
     * CPU (percent of the sandbox's CPU limit) and memory (MB) averaged per bucket; null where there's no sample.
     *
     * @return array{range: string, bucket_seconds: int, memory_limit_mb: int|null, cpu: list<array{t: int, value: float|null}>, memory: list<array{t: int, value: float|null}>}
     *
     * @throws SandboxException
     */
    public function infrastructure(Sandbox $sandbox, string $range, ?int $now = null): array
    {
        [$window, , $bucket] = self::RANGES[$range];
        $now ??= time();
        $buckets = $this->buckets($now, $window, $bucket);

        $cpu = array_fill_keys($buckets, []);
        $memory = array_fill_keys($buckets, []);
        $limit = null;

        foreach ($this->read($sandbox, self::METRICS_LOG) as $sample) {
            $at = intdiv((int) ($sample['t'] ?? 0), 1000);
            $key = intdiv($at, $bucket) * $bucket;

            if ($at <= $now - $window || ! isset($cpu[$key])) {
                continue;
            }

            if (isset($sample['cpu'])) {
                $cpu[$key][] = (float) $sample['cpu'] * 100;
            }

            if (isset($sample['mem'])) {
                $memory[$key][] = (float) $sample['mem'] / 1048576;
            }

            if (! empty($sample['memMax'])) {
                $limit = (int) round($sample['memMax'] / 1048576);
            }
        }

        $average = fn (array $values) => $values === [] ? null : round(array_sum($values) / count($values), 2);

        return [
            'range' => $range,
            'bucket_seconds' => $bucket,
            'memory_limit_mb' => $limit,
            'cpu' => array_map(fn (int $t) => ['t' => $t, 'value' => $average($cpu[$t])], $buckets),
            'memory' => array_map(fn (int $t) => ['t' => $t, 'value' => $average($memory[$t])], $buckets),
        ];
    }

    /**
     * Bucket start times (unix seconds) covering the window, oldest first.
     *
     * @return list<int>
     */
    protected function buckets(int $now, int $window, int $bucket): array
    {
        $last = intdiv($now, $bucket) * $bucket;
        $first = intdiv($now - $window, $bucket) * $bucket + $bucket;

        return range($first, $last, $bucket);
    }

    /**
     * Parsed JSON lines from a log and its rotated predecessor.
     *
     * @return iterable<array<string, mixed>>
     *
     * @throws SandboxException
     */
    public function read(Sandbox $sandbox, string $path): iterable
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c', 'cat '.$path.'.1 '.$path.' 2>/dev/null | tail -c '.self::MAX_BYTES,
        ]);

        foreach (explode("\n", $result->output) as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry)) {
                yield $entry;
            }
        }
    }
}
