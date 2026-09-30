<?php

namespace App\Sandbox;

use App\Models\Sandbox;
use Illuminate\Support\Facades\Cache;

/**
 * A browser the user takes over from one of the app's tests (TEST-005): docker/sandbox/browser.mjs runs the test up
 * to a step, stops it there with its page still open, and streams that page to a viewer under BROWSER_PATH on the
 * preview's address, for holders of its token. The user uses the page while the agent changes the app, and sees the
 * changes arrive through hot reload. Starting returns at once; status() says when the page is ready.
 */
class WorkspaceBrowser
{
    public const SCRIPT = '/opt/onedrop/browser.mjs';

    public const VIEWER_PATH = '/__onedrop/browser/';

    /** A test to run: its file and line, as the Tests tab knows it. */
    public const TARGET_PATTERN = '/^tests\/e2e\/(?:[\w-]+\/)*[\w.-]+\.(?:spec|test)\.[cm]?[jt]sx?:\d{1,6}$/';

    /** How long the agent is told about an open browser (it closes itself after 30 minutes unwatched). */
    protected const NOTE_TTL_MINUTES = 60;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Start taking over the test at $target after its $step-th step (0: before its first), replacing any browser
     * already open, and return the preview-address path of its viewer.
     *
     * @param  array{test: string, step: string|null}  $label  what the user picked, for the agent's note
     *
     * @throws SandboxException
     */
    public function open(Sandbox $sandbox, string $target, int $step, array $label): string
    {
        $result = $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'start', $target, (string) $step]);
        $token = json_decode($result->output, true)['token'] ?? null;

        if (! $result->successful() || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new SandboxException(match (true) {
                str_contains($result->errorOutput, 'Cannot find module') => __("This sandbox can't do this yet. It updates itself the next time the agent runs."),
                $result->exitCode === 3 => __("The tests need Playwright: ask the agent to add @playwright/test to the app's dev dependencies."),
                default => __("The browser didn't start."),
            });
        }

        Cache::put(self::noteKey($sandbox), $label, now()->addMinutes(self::NOTE_TTL_MINUTES));

        return self::VIEWER_PATH.'?'.http_build_query(['token' => $token]);
    }

    /**
     * Whether the browser is open, still running the test to its step, or couldn't get there (and why).
     *
     * @return array{open: bool, starting: bool, error: string|null, url: string|null, title: string|null, playback: array{paused: bool, playing: bool, step: int, next: string|null, ended: bool, error: string|null}|null}
     *
     * @throws SandboxException
     */
    public function status(Sandbox $sandbox): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'status']);
        $status = json_decode($result->output, true);

        if (! $result->successful() || ! is_array($status)) {
            throw new SandboxException(__("Couldn't check on the browser."));
        }

        if (! ($status['open'] ?? false) && ! ($status['starting'] ?? false)) {
            Cache::forget(self::noteKey($sandbox));
        }

        return [
            'open' => (bool) ($status['open'] ?? false),
            'starting' => (bool) ($status['starting'] ?? false),
            'error' => isset($status['error']) ? mb_substr((string) $status['error'], 0, 4000) : null,
            'url' => $status['url'] ?? null,
            'title' => $status['title'] ?? null,
            'playback' => is_array($status['playback'] ?? null) ? $status['playback'] : null,
        ];
    }

    /**
     * Close the browser.
     *
     * @throws SandboxException
     */
    public function close(Sandbox $sandbox): void
    {
        Cache::forget(self::noteKey($sandbox));
        $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'stop']);
    }

    /**
     * What the agent is told when the user has the browser open: which page they're on, and how to see it.
     */
    public static function agentNote(Sandbox $sandbox): ?string
    {
        $label = Cache::get(self::noteKey($sandbox));

        if (! is_array($label)) {
            return null;
        }

        $where = isset($label['step']) && $label['step'] !== ''
            ? "the test \"{$label['test']}\" up to its step \"{$label['step']}\""
            : "the test \"{$label['test']}\"";

        return "(The user has the app open in the workspace's Browser tab, a live page reached by running {$where}. "
            .'They may be pointing at something on it, and may have carried on with the test since. Your changes reach it through hot reload. '
            .'To see what they see, run `/opt/onedrop/browser screenshot /tmp/browser.png` and look at the file; `/opt/onedrop/browser status` '
            .'gives its address and where the test is.)';
    }

    protected static function noteKey(Sandbox $sandbox): string
    {
        return "onedrop.browser.{$sandbox->id}";
    }
}
