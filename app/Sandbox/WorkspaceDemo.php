<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's demo video (DEMO-001..003): a storyboard of its browser tests in .onedrop/demo.json, re-run slowly and
 * composed with Remotion into an MP4 by docker/sandbox/demo.mjs. Renders happen in the sandbox in the background;
 * this only saves the storyboard, starts them and reads the state demo.mjs keeps.
 */
class WorkspaceDemo
{
    public const SCRIPT = '/opt/onedrop/demo.mjs';

    public const GUIDE = '/opt/onedrop/guides/demo.md';

    /** Where the storyboard is kept, committed with the app. */
    public const STORYBOARD = '.onedrop/demo.json';

    /** The latest video, never committed. */
    public const VIDEO = '.onedrop/demo/demo.mp4';

    /** The chat message asking the agent to write the storyboard (DEMO-003). */
    public const WRITE_REQUEST = 'Write the storyboard for the app\'s demo video in .onedrop/demo.json, then render it. Follow the guide at '.self::GUIDE.'.';

    /** A scene's test file. */
    public const TEST_FILE_PATTERN = '/^tests\/e2e\/(?:[\w-]+\/)*[\w.-]+\.(?:spec|test)\.[cm]?[jt]sx?$/';

    /** demo.mjs's exit status when a render is already going. */
    protected const ALREADY_RUNNING = 4;

    public function __construct(protected SandboxProvider $provider, protected WorkspaceFiles $files) {}

    /**
     * The storyboard, the latest video and the render going, if any.
     *
     * @return array{running: bool, phase: string|null, progress: float, started_at: string|null, finished_at: string|null, error: string|null, video: array<mixed>|null, storyboard: array<mixed>|null, storyboard_error: string|null}
     *
     * @throws SandboxException
     */
    public function status(Sandbox $sandbox): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'status']);
        $state = json_decode($result->output, true);

        if (! $result->successful() || ! is_array($state)) {
            throw new SandboxException($this->scriptMissing($result)
                ? __("This sandbox can't make demos yet. It updates itself the next time the agent runs.")
                : __("Couldn't read the demo."));
        }

        return [
            'running' => (bool) ($state['running'] ?? false),
            'phase' => $state['phase'] ?? null,
            'progress' => (float) ($state['progress'] ?? 0),
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'error' => $state['error'] ?? null,
            'video' => is_array($state['video'] ?? null) ? $state['video'] : null,
            'storyboard' => is_array($state['storyboard'] ?? null) ? $state['storyboard'] : null,
            'storyboard_error' => $state['storyboard_error'] ?? null,
        ];
    }

    /**
     * Save the storyboard.
     *
     * @param  array{title: string, tagline: string, accent: string|null, url: string|null, scenes: list<array{file: string, title: string, caption: string}>}  $storyboard
     *
     * @throws SandboxException
     */
    public function save(Sandbox $sandbox, array $storyboard): void
    {
        $this->files->write($sandbox, self::STORYBOARD, json_encode(array_filter(
            $storyboard,
            fn ($value) => $value !== null,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * Start rendering the demo in the background, with the app's published address for the end card.
     * Returns false when a render is already going.
     *
     * @throws SandboxException
     */
    public function render(Sandbox $sandbox, ?string $publishedUrl = null): bool
    {
        $result = $this->provider->exec($sandbox->external_id, [
            'node', self::SCRIPT, 'render', '--background', ...($publishedUrl ? ['--url', $publishedUrl] : []),
        ]);

        if ($result->exitCode === self::ALREADY_RUNNING) {
            return false;
        }

        if (! $result->successful()) {
            throw new SandboxException($this->scriptMissing($result)
                ? __("This sandbox can't make demos yet. It updates itself the next time the agent runs.")
                : __("Couldn't start the render."));
        }

        return true;
    }

    /**
     * The latest video's bytes.
     *
     * @throws SandboxException
     */
    public function video(Sandbox $sandbox): string
    {
        return $this->files->bytes($sandbox, self::VIDEO);
    }

    protected function scriptMissing(ExecResult $result): bool
    {
        return str_contains($result->errorOutput.$result->output, 'Cannot find module');
    }
}
