<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Http\Controllers\ProjectRequirementsController;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\Jev;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use App\Sandbox\WorkspaceTests;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * After an agent turn, ask Jev whether the turn changed what the app does and, if so, whether the agent recorded
 * it in the requirements and wrote and ran tests for it; when it didn't, ask the agent to, once (TEST-007).
 */
class CheckRequirementsKept implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait after the turn: after the preview check (CheckPreviewErrors), so its request goes first. */
    public const DELAY_SECONDS = 12;

    /** How sure Jev must be that the turn changed the app before anything is asked. */
    public const CHANGED_THRESHOLD = 0.6;

    /** Below this, the requirements or tests count as not kept. */
    public const KEPT_THRESHOLD = 0.5;

    public int $timeout = 90;

    /**
     * @param  int|null  $turn  The user message that turn answered.
     */
    public function __construct(public Project $project, public ?int $turn) {}

    /**
     * Check the turn that just ended, when the project keeps requirements and there's a key to ask Jev with,
     * unless that turn was itself this check's request.
     */
    public static function afterTurn(Project $project): void
    {
        if (! $project->track_requirements || app(Jev::class)->endpointFor($project) === null) {
            return;
        }

        $turn = self::latestTurn($project);

        if ($turn === null || Cache::get(self::requestKey($project)) === $turn) {
            return;
        }

        self::dispatch($project, $turn)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(Jev $jev, SandboxProvider $provider, WorkspaceTests $tests, AgentQueue $queue): void
    {
        $project = $this->project->fresh();
        $sandbox = $project?->sandbox;

        // Turned off, busy again, or another turn has ended since (it gets its own check).
        if ($project === null || ! $project->track_requirements || $project->status === ProjectStatus::Working || self::latestTurn($project) !== $this->turn) {
            return;
        }

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null || ($endpoint = $jev->endpointFor($project)) === null) {
            return;
        }

        try {
            $requirements = $this->requirements($provider, $sandbox->external_id);
            $found = $tests->status($sandbox)['tests'];
        } catch (SandboxException $e) {
            report($e);

            return;
        }

        $untested = array_values(array_filter(
            self::requirementIds($requirements ?? ''),
            fn (string $id) => ! collect($found)->contains(fn (array $test) => in_array($id, $test['tags'], true)),
        ));

        try {
            $answers = $jev->yesOrNo($endpoint, $this->state($project, $requirements, $found), self::QUESTIONS);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($answers['changed_app'] < self::CHANGED_THRESHOLD) {
            return;
        }

        $missing = array_keys(array_filter([
            'requirements' => $requirements === null || $answers['recorded'] < self::KEPT_THRESHOLD,
            'tests' => $answers['tested'] < self::KEPT_THRESHOLD || $untested !== [],
        ]));

        if ($missing === []) {
            return;
        }

        $message = $queue->send($project, self::request($missing, $untested));

        Cache::put(self::requestKey($project), $message->id, now()->addDay());
    }

    /**
     * What Jev is asked about the turn, each answered with how likely it is to be true.
     *
     * @var array<string, array{instructions: string, true: string, false: string}>
     */
    public const QUESTIONS = [
        'changed_app' => [
            'instructions' => 'Did the agent change what the app does in this turn?',
            'true' => 'It built the app, added or changed a feature, or fixed how something behaves.',
            'false' => 'It only answered a question or explained something, fixed a typo, refactored without changing behavior, or changed nothing.',
        ],
        'recorded' => [
            'instructions' => 'Does the requirements file record what the user asked for in this turn?',
            'true' => 'The file has "User should be able to…" items covering this request.',
            'false' => 'The file is missing, or it has nothing about this request.',
        ],
        'tested' => [
            'instructions' => "Did the agent write or update browser tests in tests/e2e for this turn's change, and run them?",
            'true' => 'The actions show test files in tests/e2e written or edited for this change, and /opt/onedrop/run-tests run.',
            'false' => "No tests were written or updated for this change, or they weren't run.",
        ],
    ];

    /**
     * The message asking the agent to catch up on what it skipped.
     *
     * @param  list<'requirements'|'tests'>  $missing
     * @param  list<string>  $untested  requirement IDs with no tests
     */
    public static function request(array $missing, array $untested): string
    {
        $skipped = collect([
            in_array('requirements', $missing, true) ? 'record it in /workspace/.onedrop/REQ.md' : null,
            in_array('tests', $missing, true) ? 'write and run browser tests for it' : null,
        ])->filter()->implode(' or ');

        $untestedNote = $untested === [] ? '' : ' '.Str::of(implode(', ', $untested))
            ->append(count($untested) === 1 ? ' has' : ' have')
            ->append(' no tests yet; add them too.');

        return "You changed the app in your last turn but didn't {$skipped}.{$untestedNote} Do that now, following "
            .'/opt/onedrop/guides/requirements.md and '.WorkspaceTests::GUIDE.". Don't change what the app does.";
    }

    /**
     * The IDs of the requirements in REQ.md ("### REQ-001: …").
     *
     * @return list<string>
     */
    public static function requirementIds(string $requirements): array
    {
        preg_match_all('/^###\s+(REQ-\d+)\b/m', $requirements, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * REQ.md's contents, or null when the agent hasn't written it.
     *
     * @throws SandboxException
     */
    protected function requirements(SandboxProvider $provider, string $sandbox): ?string
    {
        $result = $provider->exec($sandbox, ['cat', WorkspaceFiles::ROOT.'/'.ProjectRequirementsController::PATH]);

        return $result->successful() ? $result->output : null;
    }

    /**
     * What Jev sees: the request, what the agent did and said, the requirements and the tests.
     *
     * @param  list<array{title: string, tags: list<string>, result: array{status: string}|null}>  $tests
     * @return array<string, mixed>
     */
    protected function state(Project $project, ?string $requirements, array $tests): array
    {
        $since = $project->messages()->where('id', '>', $this->turn);

        return [
            'user_request' => Str::limit($project->messages()->whereKey($this->turn)->value('content') ?? '', 4000),
            'agent_actions' => (clone $since)->where('role', MessageRole::Activity)->pluck('content')
                ->reject(fn (string $action) => $action === 'Thinking')
                ->map(fn (string $action) => Str::limit($action, 200))
                ->take(-100)->values()->all(),
            'agent_reply' => Str::limit((clone $since)->where('role', MessageRole::Assistant)->pluck('content')->implode("\n\n"), 4000),
            'requirements_file' => $requirements === null ? '(missing)' : Str::limit($requirements, 12000),
            'tests' => collect($tests)->map(fn (array $test) => trim($test['title'].' '.implode(' ', array_map(fn (string $tag) => "@{$tag}", $test['tags'])).' ('.($test['result']['status'] ?? 'not run').')'))->take(200)->values()->all(),
        ];
    }

    protected static function latestTurn(Project $project): ?int
    {
        return $project->messages()->where('role', MessageRole::User)->reorder()->latest('id')->value('id');
    }

    protected static function requestKey(Project $project): string
    {
        return "requirements-kept-request:{$project->id}";
    }
}
