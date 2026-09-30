<?php

namespace App\Sandbox;

use App\Models\ServerMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

/**
 * The server's own resources (ADMIN-003): samples of CPU time, memory, disk space, block I/O and network traffic
 * read from /proc (Linux; elsewhere only disk space), turned into chart-ready series, plus Docker's disk usage.
 */
class ServerMetrics
{
    /** Days of samples kept. */
    public const KEEP_DAYS = 7;

    /** Whole disks counted for block I/O (not their partitions, loop devices or device-mapper volumes). */
    protected const DISK = '/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/';

    /** Virtual interfaces left out of network traffic, which would count containers' traffic twice. */
    protected const VIRTUAL_INTERFACE = '/^(lo|veth|docker|br-|virbr|cni|flannel|tailscale)/';

    /** Each point's metrics. */
    protected const SERIES = ['cpu', 'memory_used', 'disk_used', 'disk_read', 'disk_written', 'network_in', 'network_out'];

    /**
     * @param  string  $proc  where /proc is (tests point it at a fixture)
     * @param  string  $disk  a path on the disk whose space is reported
     */
    public function __construct(protected string $proc = '/proc', protected ?string $disk = null) {}

    /**
     * Read the server's resources now.
     *
     * @return array{cpu_busy: int|null, cpu_total: int|null, memory_used: int|null, memory_total: int|null, disk_used: int|null, disk_total: int|null, disk_read: int|null, disk_written: int|null, network_in: int|null, network_out: int|null}
     */
    public function read(): array
    {
        return [...$this->cpu(), ...$this->memory(), ...$this->diskSpace(), ...$this->blockIo(), ...$this->network()];
    }

    /**
     * Record a sample, and delete ones older than a week.
     */
    public function record(?Carbon $at = null): ServerMetric
    {
        $at ??= now();
        ServerMetric::query()->where('recorded_at', '<', $at->copy()->subDays(self::KEEP_DAYS))->delete();

        return ServerMetric::query()->create(['recorded_at' => $at, ...$this->read()]);
    }

    /**
     * The latest values and the series for a range ("1h", "24h" or "7d"): CPU %, memory and disk used (bytes),
     * and I/O and network rates (bytes per second), averaged into buckets; null where nothing was recorded.
     *
     * @return array{range: string, bucket_seconds: int, current: array<string, int|float|null>, series: list<array<string, int|float|null>>}
     */
    public function summary(string $range, ?int $now = null): array
    {
        [$window, , $bucket] = SandboxMonitoring::RANGES[$range];
        $now ??= time();
        $first = intdiv($now - $window, $bucket) * $bucket + $bucket;

        // One sample before the window, so the first bucket has a rate.
        $samples = ServerMetric::query()
            ->where('recorded_at', '>=', Carbon::createFromTimestamp($first - $bucket - 120))
            ->where('recorded_at', '<=', Carbon::createFromTimestamp($now))
            ->orderBy('recorded_at')
            ->get();

        $sums = [];
        $previous = null;

        foreach ($samples as $sample) {
            $point = $this->point($sample, $previous);
            $previous = $sample;
            $at = $sample->recorded_at->getTimestamp();

            if ($at < $first) {
                continue;
            }

            $key = intdiv($at - $first, $bucket) * $bucket + $first;

            foreach ($point as $metric => $value) {
                if ($value !== null) {
                    $sums[$key][$metric][] = $value;
                }
            }
        }

        $series = [];

        for ($t = $first; $t <= $now; $t += $bucket) {
            $series[] = ['t' => $t, ...array_map(
                fn (string $metric) => isset($sums[$t][$metric]) ? array_sum($sums[$t][$metric]) / count($sums[$t][$metric]) : null,
                array_combine(self::SERIES, self::SERIES),
            )];
        }

        $latest = $samples->last();
        $before = $samples->count() > 1 ? $samples[$samples->count() - 2] : null;

        return [
            'range' => $range,
            'bucket_seconds' => $bucket,
            'current' => $latest === null ? [] : [
                'recorded_at' => $latest->recorded_at->getTimestamp(),
                'cpu' => $before !== null ? $this->point($latest, $before)['cpu'] : null,
                ...$latest->only(['memory_used', 'memory_total', 'disk_used', 'disk_total', 'disk_read', 'disk_written', 'network_in', 'network_out']),
            ],
            'series' => $series,
        ];
    }

    /**
     * Docker's disk usage by type (images, containers, local volumes, build cache), or null without Docker.
     *
     * @return list<array{type: string, total: int, active: int, size: string, reclaimable: string}>|null
     */
    public function dockerDiskUsage(): ?array
    {
        $result = Process::timeout(30)->run(['docker', 'system', 'df', '--format', '{{json .}}']);

        if ($result->failed()) {
            return null;
        }

        $rows = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            $row = json_decode($line, true);

            if (is_array($row) && isset($row['Type'])) {
                $rows[] = [
                    'type' => (string) $row['Type'],
                    'total' => (int) ($row['TotalCount'] ?? 0),
                    'active' => (int) ($row['Active'] ?? 0),
                    'size' => (string) ($row['Size'] ?? ''),
                    'reclaimable' => (string) ($row['Reclaimable'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * One sample as chart values: gauges as they are, counters as rates since the sample before (none after a
     * reboot, when they start again from zero).
     *
     * @return array<string, int|float|null>
     */
    protected function point(ServerMetric $sample, ?ServerMetric $previous): array
    {
        $seconds = $previous ? $sample->recorded_at->getTimestamp() - $previous->recorded_at->getTimestamp() : 0;
        $rate = function (string $counter) use ($sample, $previous, $seconds): ?float {
            if ($previous === null || $seconds <= 0 || $sample->{$counter} === null || $previous->{$counter} === null || $sample->{$counter} < $previous->{$counter}) {
                return null;
            }

            return ($sample->{$counter} - $previous->{$counter}) / $seconds;
        };

        $cpuTotal = $previous ? (int) $sample->cpu_total - (int) $previous->cpu_total : 0;
        $cpuBusy = $previous ? (int) $sample->cpu_busy - (int) $previous->cpu_busy : 0;

        return [
            'cpu' => $previous && $sample->cpu_total !== null && $previous->cpu_total !== null && $cpuTotal > 0 && $cpuBusy >= 0
                ? round(min(100, $cpuBusy / $cpuTotal * 100), 2)
                : null,
            'memory_used' => $sample->memory_used,
            'disk_used' => $sample->disk_used,
            'disk_read' => $rate('disk_read'),
            'disk_written' => $rate('disk_written'),
            'network_in' => $rate('network_in'),
            'network_out' => $rate('network_out'),
        ];
    }

    /**
     * CPU time since boot from /proc/stat: busy (everything but idle and iowait) and total, in jiffies.
     *
     * @return array{cpu_busy: int|null, cpu_total: int|null}
     */
    protected function cpu(): array
    {
        $line = $this->lines('stat')[0] ?? '';

        if (! str_starts_with($line, 'cpu ')) {
            return ['cpu_busy' => null, 'cpu_total' => null];
        }

        // user nice system idle iowait irq softirq steal (guest time is already in user).
        $times = array_map('intval', array_slice(preg_split('/\s+/', trim($line)) ?: [], 1, 8));
        $total = array_sum($times);
        $idle = ($times[3] ?? 0) + ($times[4] ?? 0);

        return ['cpu_busy' => $total - $idle, 'cpu_total' => $total];
    }

    /**
     * Memory in use (total less what's available) from /proc/meminfo, in bytes.
     *
     * @return array{memory_used: int|null, memory_total: int|null}
     */
    protected function memory(): array
    {
        $info = [];

        foreach ($this->lines('meminfo') as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $match)) {
                $info[$match[1]] = (int) $match[2] * 1024;
            }
        }

        if (! isset($info['MemTotal'], $info['MemAvailable'])) {
            return ['memory_used' => null, 'memory_total' => null];
        }

        return ['memory_used' => $info['MemTotal'] - $info['MemAvailable'], 'memory_total' => $info['MemTotal']];
    }

    /**
     * Space on the app's disk, in bytes.
     *
     * @return array{disk_used: int|null, disk_total: int|null}
     */
    protected function diskSpace(): array
    {
        $path = $this->disk ?? base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false) {
            return ['disk_used' => null, 'disk_total' => null];
        }

        return ['disk_used' => (int) ($total - $free), 'disk_total' => (int) $total];
    }

    /**
     * Bytes read from and written to the server's disks since boot, from /proc/diskstats (512-byte sectors).
     *
     * @return array{disk_read: int|null, disk_written: int|null}
     */
    protected function blockIo(): array
    {
        $lines = $this->lines('diskstats');

        if ($lines === []) {
            return ['disk_read' => null, 'disk_written' => null];
        }

        $read = $written = 0;

        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) >= 10 && preg_match(self::DISK, $fields[2])) {
                $read += (int) $fields[5] * 512;
                $written += (int) $fields[9] * 512;
            }
        }

        return ['disk_read' => $read, 'disk_written' => $written];
    }

    /**
     * Bytes received and sent since boot on the server's real network interfaces, from /proc/net/dev.
     *
     * @return array{network_in: int|null, network_out: int|null}
     */
    protected function network(): array
    {
        $lines = $this->lines('net/dev');

        if ($lines === []) {
            return ['network_in' => null, 'network_out' => null];
        }

        $in = $out = 0;

        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$interface, $counters] = array_map('trim', explode(':', $line, 2));
            $fields = preg_split('/\s+/', $counters) ?: [];

            if (count($fields) >= 9 && ! preg_match(self::VIRTUAL_INTERFACE, $interface)) {
                $in += (int) $fields[0];
                $out += (int) $fields[8];
            }
        }

        return ['network_in' => $in, 'network_out' => $out];
    }

    /**
     * A /proc file's lines, or [] where it doesn't exist (macOS, Windows).
     *
     * @return list<string>
     */
    protected function lines(string $file): array
    {
        $path = "{$this->proc}/{$file}";
        $content = is_readable($path) ? @file_get_contents($path) : false;

        return $content === false ? [] : array_values(array_filter(preg_split('/\R/', $content) ?: [], fn (string $line) => $line !== ''));
    }
}
