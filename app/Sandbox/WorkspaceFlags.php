<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's feature flags. They live in .onedrop/flags.json, which the app reads each time it checks a flag
 * (the agent sets that up following docker/sandbox/guides/flags.md), so switching one here applies right away.
 */
class WorkspaceFlags
{
    public const FILE = '/workspace/.onedrop/flags.json';

    public const GUIDE = '/opt/onedrop/guides/flags.md';

    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /** Exit status of SET_SCRIPT when the flag isn't in the file. */
    protected const MISSING = 3;

    /**
     * Turns the flag $argv[2] on ("1") or off in the flags file $argv[1], replacing the file in one step.
     * Runs inside the sandbox.
     */
    protected const SET_SCRIPT = <<<'PHP'
        [, $path, $key, $on] = $argv;
        $data = json_decode((string) @file_get_contents($path), true);
        is_array($data) && is_array($data['flags'] ?? null) || exit(3);
        $found = false;
        foreach ($data['flags'] as &$flag) {
            if (is_array($flag) && ($flag['key'] ?? null) === $key) {
                $flag['enabled'] = $on === '1';
                $found = true;
            }
        }
        unset($flag);
        $found || exit(3);
        $temp = $path.'.onedrop-tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        file_put_contents($temp, $json) !== false && rename($temp, $path) || exit(1);
        PHP;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Every flag in the file, in its order; empty when there's no file yet.
     *
     * @return list<array{key: string, description: string|null, enabled: bool}>
     *
     * @throws SandboxException
     */
    public function flags(Sandbox $sandbox): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['sh', '-c', 'cat '.self::FILE.' 2>/dev/null']);
        $data = json_decode($result->output, true);
        $flags = [];

        foreach (is_array($data['flags'] ?? null) ? $data['flags'] : [] as $flag) {
            $key = is_array($flag) ? ($flag['key'] ?? null) : null;

            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key) || isset($flags[$key])) {
                continue;
            }

            $description = is_string($flag['description'] ?? null) ? trim($flag['description']) : '';

            $flags[$key] = [
                'key' => $key,
                'description' => $description === '' ? null : mb_substr($description, 0, 500),
                'enabled' => ($flag['enabled'] ?? false) === true,
            ];
        }

        return array_slice(array_values($flags), 0, 200);
    }

    /**
     * Turn a flag on or off.
     *
     * @throws DatabaseException when the flag isn't in the file
     * @throws SandboxException
     */
    public function set(Sandbox $sandbox, string $key, bool $enabled): void
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'php', '-r', self::SET_SCRIPT, '--', self::FILE, $key, $enabled ? '1' : '0',
        ]);

        if ($result->exitCode === self::MISSING) {
            throw new DatabaseException(__("That flag isn't in your app anymore. It may have been removed."));
        }

        if (! $result->successful()) {
            throw new SandboxException(__("Couldn't save the flag."));
        }
    }

    /** The chat message asking the agent to put a feature behind a new flag. */
    public static function addRequest(string $feature): string
    {
        return 'Put this behind a feature flag: '.trim($feature).' Follow the guide at '.self::GUIDE.'.';
    }

    /** The chat message asking the agent to take a flag out, keeping the feature as it is now. */
    public static function removeRequest(string $key, bool $enabled): string
    {
        $keep = $enabled ? 'keep the feature on for everyone' : 'leave the feature off (remove its code)';

        return "Remove the feature flag \"{$key}\" and {$keep}. Follow the guide at ".self::GUIDE.'.';
    }
}
