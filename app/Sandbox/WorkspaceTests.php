<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * The app's browser tests (TEST-001..003): Playwright tests in tests/e2e that the agent writes for each requirement,
 * run in the sandbox by docker/sandbox/tests.mjs with a video and a trace of each. Runs happen in the sandbox in the
 * background; this only starts them and reads the state tests.mjs keeps.
 */
class WorkspaceTests
{
    public const SCRIPT = '/opt/onedrop/tests.mjs';

    public const GUIDE = '/opt/onedrop/guides/tests.md';

    /** The chat message asking the agent to write the tests requirements don't have yet (TEST-002). */
    public const WRITE_REQUEST = 'Write browser tests for the requirements in .onedrop/REQ.md that don\'t have any yet, then run them all and fix anything that fails. Follow the guide at '.self::GUIDE.'.';

    /** What a run can be asked for: a test file (optionally at a line), or a tag such as @REQ-001. */
    public const TARGET_PATTERN = '/^(tests\/e2e\/(?:[\w-]+\/)*[\w.-]+\.(?:spec|test)\.[cm]?[jt]sx?(?::\d{1,6})?|@[A-Za-z0-9_-]{1,64})$/';

    /** A test's recording: its video or trace, in the folder tests.mjs gives each run. */
    public const RECORDING_PATTERN = '/^\.onedrop\/tests\/runs\/\d{1,20}\/[\w.-]+\/(video\.webm|trace\.zip)$/';

    /** Where host-proxy.mjs serves the test runner on the preview's address, and the parameter carrying its token. */
    public const RUNNER_PATH = '/__onedrop/tests-ui/';

    public const RUNNER_TOKEN_PARAMETER = 'onedrop_tests_ui';

    /** tests.mjs's exit status when a run is already going. */
    protected const ALREADY_RUNNING = 4;

    public function __construct(protected SandboxProvider $provider, protected WorkspaceFiles $files) {}

    /**
     * The app's tests and each one's latest result. Unless $cached (or a run is going), tests.mjs looks for tests
     * again first, so new ones show before they have run.
     *
     * @return array{running: bool, started_at: string|null, finished_at: string|null, error: string|null, tests: list<array{id: string, file: string, line: int, title: string, tags: list<string>, result: array{status: string, duration: int, error: string|null, video: string|null, trace: string|null, ran_at: string}|null}>}
     *
     * @throws SandboxException
     */
    public function status(Sandbox $sandbox, bool $cached = false): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'status', ...($cached ? ['--cached'] : [])]);
        $state = json_decode($result->output, true);

        if (! $result->successful() || ! is_array($state)) {
            throw new SandboxException($this->scriptMissing($result)
                ? __("This sandbox can't run tests yet. It updates itself the next time the agent runs.")
                : __("Couldn't read the tests."));
        }

        return [
            'running' => (bool) ($state['running'] ?? false),
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'error' => $state['error'] ?? null,
            'tests' => array_values(array_filter($state['tests'] ?? [], 'is_array')),
        ];
    }

    /**
     * Start running the given tests (all of them when none), in the background.
     * Returns false when a run is already going.
     *
     * @param  list<string>  $targets  each matching TARGET_PATTERN
     *
     * @throws SandboxException
     */
    public function run(Sandbox $sandbox, array $targets = []): bool
    {
        $result = $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'run', '--background', ...$targets]);

        if ($result->exitCode === self::ALREADY_RUNNING) {
            return false;
        }

        if (! $result->successful()) {
            throw new SandboxException($this->scriptMissing($result)
                ? __("This sandbox can't run tests yet. It updates itself the next time the agent runs.")
                : __("Couldn't start the tests."));
        }

        return true;
    }

    /**
     * Start the test runner (Playwright's UI mode, TEST-004), or find the one already going, and return the
     * preview-address path that opens it: the runner only lets in browsers that came with its token.
     *
     * @throws SandboxException
     */
    public function openRunner(Sandbox $sandbox): string
    {
        $result = $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'ui', 'start']);
        $token = json_decode($result->output, true)['token'] ?? null;

        if (! $result->successful() || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new SandboxException(match (true) {
                $this->scriptMissing($result) => __("This sandbox can't run tests yet. It updates itself the next time the agent runs."),
                $result->exitCode === 2 => __('There are no tests to open yet.'),
                $result->exitCode === 3 => __("The tests need Playwright: ask the agent to add @playwright/test to the app's dev dependencies."),
                default => __("The test runner didn't start.").(trim($result->errorOutput) !== '' ? ' '.str($result->errorOutput)->trim()->afterLast("\n")->limit(300) : ''),
            });
        }

        return self::RUNNER_PATH.'?'.http_build_query([self::RUNNER_TOKEN_PARAMETER => $token]);
    }

    /**
     * Stop the test runner; its links stop working.
     *
     * @throws SandboxException
     */
    public function closeRunner(Sandbox $sandbox): void
    {
        $this->provider->exec($sandbox->external_id, ['node', self::SCRIPT, 'ui', 'stop']);
    }

    /**
     * A test's video or trace.
     *
     * @throws SandboxException
     */
    public function recording(Sandbox $sandbox, string $path): string
    {
        return $this->files->bytes($sandbox, $path);
    }

    protected function scriptMissing(ExecResult $result): bool
    {
        return str_contains($result->errorOutput.$result->output, 'Cannot find module');
    }
}
