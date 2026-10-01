<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Visitor analytics (who came, from where (country and city), to which pages, on what) from the same request log
 * Monitoring reads, written by docker/sandbox/host-proxy.mjs.
 */
class SandboxGrowth
{
    /**
     * Range => [window seconds, bucket seconds].
     *
     * @var array<string, array{int, int}>
     */
    public const RANGES = [
        '24h' => [86400, 3600],
        '7d' => [604800, 3600],
        '30d' => [2592000, 86400],
    ];

    /** Entries per top list. */
    public const TOP = 10;

    /** Most cities placed on the map. */
    public const MAP_CITIES = 100;

    /** Files that aren't pages (scripts, styles, images, fonts, feeds). */
    protected const ASSET = '/\.(m?js|css|map|png|jpe?g|gif|svg|ico|webp|avif|woff2?|ttf|otf|eot|txt|xml|json|webmanifest)$/i';

    public function __construct(protected SandboxMonitoring $monitoring) {}

    /**
     * @return array{range: string, bucket_seconds: int, since: int, visitors: int, previous_visitors: int, page_views: int, visitors_over_time: list<array{t: int, value: int}>, pages: list<array{label: string, count: int}>, referrers: list<array{label: string, count: int}>, countries: list<array{label: string, count: int}>, browsers: list<array{label: string, count: int}>, devices: list<array{label: string, count: int}>, locations: array{countries: list<array{label: string, count: int}>, cities: list<array{city: string, region: string|null, country: string, lat: float|null, lon: float|null, count: int}>}}
     *
     * @throws SandboxException
     */
    public function analytics(Sandbox $sandbox, string $range, bool $publishedOnly = false, ?int $now = null): array
    {
        [$window, $bucket] = self::RANGES[$range];
        $now ??= time();
        $last = intdiv($now, $bucket) * $bucket;
        $buckets = range(intdiv($now - $window, $bucket) * $bucket + $bucket, $last, $bucket);

        $perBucket = array_fill_keys($buckets, []);
        $visitors = [];
        $previous = [];
        $pages = [];
        $pageViews = 0;
        /** @var array<string, array<string, array<string, true>>> $groups visitors per label, per breakdown */
        $groups = ['referrers' => [], 'countries' => [], 'browsers' => [], 'devices' => []];
        /** @var array<string, array{city: string, region: string|null, country: string, lat: float|null, lon: float|null, visitors: array<string, true>}> $cities */
        $cities = [];

        foreach ($this->monitoring->read($sandbox, SandboxMonitoring::ACCESS_LOG) as $entry) {
            $at = intdiv((int) ($entry['t'] ?? 0), 1000);
            $ip = (string) ($entry['ip'] ?? '');

            if ($ip === '' || $at > $now || $at <= $now - 2 * $window || ($publishedOnly && ! ($entry['pub'] ?? false))) {
                continue;
            }

            if ($at <= $now - $window) {
                $previous[$ip] = true;

                continue;
            }

            $visitors[$ip] = true;
            $perBucket[intdiv($at, $bucket) * $bucket][$ip] = true;

            if ($this->isPageView($entry)) {
                $pageViews++;
                $pages[$entry['p']] = ($pages[$entry['p']] ?? 0) + 1;
            }

            if (! empty($entry['r'])) {
                $groups['referrers'][(string) $entry['r']][$ip] = true;
            }

            if (! empty($entry['c'])) {
                $groups['countries'][(string) $entry['c']][$ip] = true;

                if (! empty($entry['ci'])) {
                    $this->addCity($cities, $entry, $ip);
                }
            }

            [$browser, $device] = self::userAgent((string) ($entry['ua'] ?? ''));
            $groups['browsers'][$browser][$ip] = true;
            $groups['devices'][$device][$ip] = true;
        }

        return [
            'range' => $range,
            'bucket_seconds' => $bucket,
            'since' => $now - $window,
            'visitors' => count($visitors),
            'previous_visitors' => count($previous),
            'page_views' => $pageViews,
            'visitors_over_time' => array_map(fn (int $t) => ['t' => $t, 'value' => count($perBucket[$t])], $buckets),
            'pages' => $this->top($pages),
            'referrers' => $this->top(array_map('count', $groups['referrers'])),
            'countries' => $this->top(array_map('count', $groups['countries'])),
            'browsers' => $this->top(array_map('count', $groups['browsers'])),
            'devices' => $this->top(array_map('count', $groups['devices'])),
            'locations' => [
                'countries' => $this->top(array_map('count', $groups['countries']), PHP_INT_MAX),
                'cities' => $this->topCities($cities),
            ],
        ];
    }

    /**
     * Count a visitor in their city, placed where the first entry with coordinates says it is.
     *
     * @param  array<string, array{city: string, region: string|null, country: string, lat: float|null, lon: float|null, visitors: array<string, true>}>  $cities
     * @param  array<string, mixed>  $entry
     */
    protected function addCity(array &$cities, array $entry, string $ip): void
    {
        $region = empty($entry['rg']) ? null : (string) $entry['rg'];
        $key = $entry['c'].'|'.$region.'|'.$entry['ci'];
        $cities[$key] ??= ['city' => (string) $entry['ci'], 'region' => $region, 'country' => (string) $entry['c'], 'lat' => null, 'lon' => null, 'visitors' => []];
        $cities[$key]['visitors'][$ip] = true;

        $lat = $entry['la'] ?? null;
        $lon = $entry['lo'] ?? null;

        if ($cities[$key]['lat'] === null && is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180) {
            $cities[$key]['lat'] = (float) $lat;
            $cities[$key]['lon'] = (float) $lon;
        }
    }

    /**
     * The cities with the most visitors, largest first (ties alphabetical).
     *
     * @param  array<string, array{city: string, region: string|null, country: string, lat: float|null, lon: float|null, visitors: array<string, true>}>  $cities
     * @return list<array{city: string, region: string|null, country: string, lat: float|null, lon: float|null, count: int}>
     */
    protected function topCities(array $cities): array
    {
        $rows = array_map(function (array $city) {
            $city['count'] = count($city['visitors']);
            unset($city['visitors']);

            return $city;
        }, array_values($cities));
        usort($rows, fn (array $a, array $b) => [$b['count'], $a['city'], $a['region'] ?? ''] <=> [$a['count'], $b['city'], $b['region'] ?? '']);

        return array_slice($rows, 0, self::MAP_CITIES);
    }

    /**
     * Browser and device type from a User-Agent header.
     *
     * @return array{string, string}
     */
    public static function userAgent(string $userAgent): array
    {
        if ($userAgent === '') {
            return ['Unknown', 'Unknown'];
        }

        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|curl|wget|python|go-http|headless/i', $userAgent)) {
            return ['Bot', 'Bot'];
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg') => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $userAgent) => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/', $userAgent) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS|Chromium/', $userAgent) => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => 'Other',
        };

        $device = match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $userAgent) => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|Android/i', $userAgent) => 'Mobile',
            default => 'Desktop',
        };

        return [$browser, $device];
    }

    /**
     * A successful GET of something other than a static file.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function isPageView(array $entry): bool
    {
        $status = (int) ($entry['s'] ?? 0);

        return isset($entry['p']) && ($entry['m'] ?? 'GET') === 'GET'
            && $status >= 200 && $status < 300
            && ! preg_match(self::ASSET, (string) $entry['p']);
    }

    /**
     * The biggest counts, largest first (ties alphabetical).
     *
     * @param  array<string, int>  $counts
     * @return list<array{label: string, count: int}>
     */
    protected function top(array $counts, int $limit = self::TOP): array
    {
        $rows = array_map(fn ($label, int $count) => ['label' => (string) $label, 'count' => $count], array_keys($counts), $counts);
        usort($rows, fn (array $a, array $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return array_slice($rows, 0, $limit);
    }
}
