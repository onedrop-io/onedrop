<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\Jev;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ask Jev why each failing test in the Tests tab failed (TEST-008): the app broke, the test is out of date (the app
 * changed on purpose), or it's flaky. Each verdict is worked out once per result (a test's run), in the background,
 * and kept; the tests' status only reads the kept verdicts and starts this for the failures that have none yet.
 */
class TriageFailingTests implements ShouldQueue
{
    use Queueable;

    /** What a failure can be put down to, and what each means to Jev. */
    public const VERDICTS = [
        'app' => 'The app broke: it no longer does what the test checks, and it should (a bug, a crash, an error page, a missing part the app is meant to have). The test still describes what the user wants.',
        'test' => 'The test is out of date: the app was changed on purpose (as the user asked: new text, a renamed or moved button, a changed flow), and the test still expects the old behavior.',
        'flaky' => 'Flaky or timing: the app does what the test checks, but the test failed on timing, a race, slow loading, an animation, or something outside the app (the network, the sandbox).',
    ];

    /** How sure Jev must be of its top choice for it to be shown. */
    public const SHOWN_THRESHOLD = 0.5;

    /** Most failing tests asked about in one request; the rest get the next. */
    public const MAX_TESTS = 8;

    /** Most lines of a test's source sent, from its first line. */
    public const MAX_SOURCE_LINES = 60;

    public int $timeout = 90;

    /**
     * @param  list<array{id: string, file: string, line: int, title: string, result: array<string, mixed>}>  $tests  failing tests with no verdict yet
     */
    public function __construct(public Project $project, public array $tests) {}

    /**
     * Add each failing test's verdict (null when there's none, or Jev wasn't sure) to the tests' status, and
     * `triage_pending` when some are still being worked out, starting that for the failures without one.
     * Without a key to ask Jev with, nothing is added.
     *
     * @param  array{tests: list<array<string, mixed>>}  $status
     * @return array<string, mixed>
     */
    public static function annotate(Project $project, array $status): array
    {
        if (app(Jev::class)->endpointFor($project) === null) {
            return $status;
        }

        $untriaged = [];

        foreach ($status['tests'] as $i => $test) {
            if (($test['result']['status'] ?? null) !== 'failed') {
                continue;
            }

            $kept = Cache::get(self::verdictKey($project, $test));
            $status['tests'][$i]['result']['triage'] = is_array($kept) && $kept['verdict'] !== null ? $kept : null;

            if ($kept === null) {
                $untriaged[] = $test;
            }
        }

        // One triage at a time per project; the lock goes when it's done (or after a while, if it never runs).
        if ($untriaged !== [] && Cache::add(self::pendingKey($project), true, now()->addMinutes(5))) {
            self::dispatch($project, array_slice($untriaged, 0, self::MAX_TESTS));
        }

        $status['triage_pending'] = $untriaged !== [] && Cache::has(self::pendingKey($project));

        return $status;
    }

    public function handle(Jev $jev, WorkspaceFiles $files): void
    {
        $project = $this->project->fresh();
        $sandbox = $project?->sandbox;

        try {
            if ($project === null || $sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null || ($endpoint = $jev->endpointFor($project)) === null) {
                return;
            }

            $state = $this->state($project, $this->sources($files, $sandbox));
            $questions = [];

            foreach (array_keys($this->tests) as $i) {
                $questions["test_{$i}"] = Jev::choiceQuestion("Why did tests.test_{$i} fail?", self::VERDICTS);
            }

            try {
                $answers = $jev->decide($endpoint, $state, $questions);
            } catch (Throwable $e) {
                report($e);

                // Asked again in a while, not on every look at the tab.
                foreach ($this->tests as $test) {
                    Cache::put(self::verdictKey($project, $test), ['verdict' => null], now()->addMinutes(10));
                }

                return;
            }

            foreach ($this->tests as $i => $test) {
                $answer = $answers["test_{$i}"];
                $probability = (float) ($answer['probabilities'][$answer['choice']] ?? 0);

                Cache::put(self::verdictKey($project, $test), [
                    'verdict' => isset(self::VERDICTS[$answer['choice']]) && $probability >= self::SHOWN_THRESHOLD ? $answer['choice'] : null,
                    'probability' => round($probability, 2),
                ], now()->addDays(30));
            }
        } finally {
            if ($project !== null) {
                Cache::forget(self::pendingKey($project));
            }
        }
    }

    /**
     * Each test file's text, by path (left out when it can't be read).
     *
     * @return array<string, string>
     */
    protected function sources(WorkspaceFiles $files, Sandbox $sandbox): array
    {
        $sources = [];

        foreach (array_unique(array_column($this->tests, 'file')) as $file) {
            try {
                $sources[$file] = $files->read($sandbox, $file)['content'] ?? '';
            } catch (SandboxException) {
                $sources[$file] = '';
            }
        }

        return $sources;
    }

    /**
     * What Jev sees: what the user asked for lately, what the agent did, and each failing test with its error,
     * steps and source.
     *
     * @param  array<string, string>  $sources
     * @return array<string, mixed>
     */
    protected function state(Project $project, array $sources): array
    {
        return [
            'recent_user_requests' => $project->messages()->where('role', MessageRole::User)->reorder()->latest('id')
                ->limit(5)->pluck('content')->reverse()->map(fn (string $request) => Str::limit($request, 500))->values()->all(),
            'recent_agent_actions' => $project->messages()->where('role', MessageRole::Activity)->reorder()->latest('id')
                ->where('content', '!=', 'Thinking')->limit(40)->pluck('content')->reverse()->map(fn (string $action) => Str::limit($action, 150))->values()->all(),
            'tests' => collect($this->tests)->mapWithKeys(fn (array $test, int $i) => ["test_{$i}" => array_filter([
                'title' => Str::limit($test['title'], 300),
                'file' => $test['file'],
                'error' => Str::limit((string) ($test['result']['error'] ?? ''), 1500),
                'steps_run' => collect($test['result']['steps'] ?? [])->take(30)
                    ->map(fn (array $step) => Str::limit(trim(($step['title'] ?? '').' '.($step['subtitle'] ?? '')), 150))->all(),
                'source' => self::testSource($sources[$test['file']] ?? '', (int) $test['line']),
            ])])->all(),
        ];
    }

    /**
     * The test's own lines in its file: from its line to the next test, at most MAX_SOURCE_LINES.
     */
    public static function testSource(string $file, int $line): string
    {
        $lines = array_slice(explode("\n", $file), max(0, $line - 1), self::MAX_SOURCE_LINES);

        foreach ($lines as $i => $text) {
            if ($i > 0 && preg_match('/^\s*test(\.\w+)?\(/', $text)) {
                $lines = array_slice($lines, 0, $i);
                break;
            }
        }

        return Str::limit(rtrim(implode("\n", $lines)), 3000);
    }

    /**
     * Where a result's verdict is kept: one per test and run.
     *
     * @param  array{id: string, result: array<string, mixed>}  $test
     */
    public static function verdictKey(Project $project, array $test): string
    {
        return "test-triage:{$project->id}:".sha1($test['id'].'|'.($test['result']['ran_at'] ?? ''));
    }

    protected static function pendingKey(Project $project): string
    {
        return "test-triage-pending:{$project->id}";
    }
}
