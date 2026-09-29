<?php

namespace App\Sandbox;

use App\Models\Sandbox;
use Illuminate\Support\Str;

/**
 * The app's errors, whatever its stack, as docker/sandbox/host-proxy.mjs records them in
 * /workspace/.zap/errors.log (ERR-001): 5xx answers, errors in the preview's browser, and the app not answering.
 */
class PreviewErrors
{
    public const PATH = '/workspace/.zap/errors.log';

    /** Most log bytes read (newest kept). */
    public const MAX_BYTES = 200_000;

    /** Browser errors worth a fix; console output alone (framework warnings and the like) isn't. */
    public const FIXABLE_BROWSER_TYPES = ['error', 'rejection', 'resource'];

    /** Most errors listed in a request to the agent. */
    public const MAX_LISTED = 5;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Load the app's home page through the preview's proxy (so a broken one gets recorded), then return the
     * errors worth fixing that the preview recorded since $since (Unix ms), oldest first.
     * Published-address errors are left out: visitors shouldn't be able to set the agent to work.
     *
     * @return list<array<mixed>>
     *
     * @throws SandboxException
     */
    public function check(Sandbox $sandbox, int $since): array
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'sh', '-c',
            'curl -s -o /dev/null -w "%{http_code}" --max-time 30 "http://127.0.0.1:${PROXY_PORT:-8081}/"; echo; '
            .'sleep 1; tail -c '.self::MAX_BYTES.' '.self::PATH.' 2>/dev/null; true',
        ]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't check the app for errors.");
        }

        [$status, $log] = array_pad(explode("\n", $result->output, 2), 2, '');
        // The proxy answers 502 itself when the app is down; any other answer means it's up now.
        $down = in_array(trim($status), ['', '000', '502'], true);

        return array_values(collect(explode("\n", $log))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($error) => is_array($error)
                && (int) ($error['t'] ?? 0) >= $since
                && ! ($error['pub'] ?? false)
                && match ($error['k'] ?? null) {
                    'server' => true,
                    'down' => $down,
                    'browser' => in_array($error['type'] ?? null, self::FIXABLE_BROWSER_TYPES, true),
                    default => false,
                })
            ->unique(fn (array $error) => self::describe($error))
            ->all());
    }

    /**
     * The message asking the agent to fix the errors.
     *
     * @param  list<array<mixed>>  $errors
     */
    public static function request(array $errors): string
    {
        $lines = collect($errors)
            ->take(-self::MAX_LISTED)
            ->map(fn (array $error) => str_replace('```', "'''", self::describe($error)))
            ->implode("\n");

        return "The app shows errors after your last change:\n\n```\n{$lines}\n```\n\n"
            .'Details (page text, stack traces, the end of the server log) are in '.self::PATH.'. '
            .'Find the cause, fix it, and check the preview loads without errors.';
    }

    /**
     * One line saying what went wrong.
     *
     * @param  array<mixed>  $error
     */
    public static function describe(array $error): string
    {
        $where = trim(($error['m'] ?? '').' '.($error['p'] ?? $error['page'] ?? '/'));

        return Str::limit(match ($error['k'] ?? null) {
            'server' => "Server error {$error['s']} on {$where}".(isset($error['text']) ? ": {$error['text']}" : ''),
            'down' => "The app didn't answer on {$where}",
            default => match ($error['type'] ?? null) {
                'rejection' => 'Unhandled promise rejection',
                'resource' => 'Failed to load',
                default => 'Browser error',
            }." on {$where}: ".($error['msg'] ?? ''),
        }, 300);
    }
}
