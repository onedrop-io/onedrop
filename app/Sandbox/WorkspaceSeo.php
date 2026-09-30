<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's SEO rating. The agent checks the app (following docker/sandbox/guides/seo.md)
 * and writes the result to .onedrop/seo.json, which this reads for Tools → Growth.
 */
class WorkspaceSeo
{
    public const REPORT = '/workspace/.onedrop/seo.json';

    public const GUIDE = '/opt/onedrop/guides/seo.md';

    public const STATUSES = ['pass', 'warn', 'fail'];

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * The latest scan, or null when the agent hasn't run one (or the file isn't readable).
     *
     * @return array{score: int, scanned_at: string|null, summary: string|null, checks: list<array{title: string, status: string, detail: string|null}>}|null
     *
     * @throws SandboxException
     */
    public function report(Sandbox $sandbox): ?array
    {
        $result = $this->provider->exec($sandbox->external_id, ['sh', '-c', 'cat '.self::REPORT.' 2>/dev/null']);
        $report = json_decode($result->output, true);

        if (! is_array($report) || ! is_numeric($report['score'] ?? null)) {
            return null;
        }

        $text = fn (mixed $value, int $limit): ?string => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $limit) : null;
        $checks = [];

        foreach (is_array($report['checks'] ?? null) ? $report['checks'] : [] as $check) {
            if (is_array($check) && ($title = $text($check['title'] ?? null, 200)) !== null) {
                $checks[] = [
                    'title' => $title,
                    'status' => in_array($check['status'] ?? null, self::STATUSES, true) ? $check['status'] : 'warn',
                    'detail' => $text($check['detail'] ?? null, 1000),
                ];
            }
        }

        return [
            'score' => max(0, min(100, (int) round((float) $report['score']))),
            'scanned_at' => $text($report['scanned_at'] ?? null, 40),
            'summary' => $text($report['summary'] ?? null, 1000),
            'checks' => array_slice($checks, 0, 50),
        ];
    }

    /**
     * The chat message asking the agent to scan the app, and with $fix to fix what it finds too.
     */
    public static function request(bool $fix = false): string
    {
        $guide = 'Follow the guide at '.self::GUIDE.'.';

        return $fix
            ? "Check my app's SEO and fix the problems you find. {$guide}"
            : "Check my app's SEO and tell me what to improve. Don't change the app yet. {$guide}";
    }
}
