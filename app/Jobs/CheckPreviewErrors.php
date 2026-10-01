<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\Jev;
use App\Sandbox\PreviewErrors;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * After an agent turn, look for new errors in the preview and send them to the agent, once (ERR-001). When there's
 * a key to ask Jev with, only the errors it judges real problems in the app are sent, not noise (a hot-reload
 * hiccup, a browser extension, a missing favicon); without Jev, or when it fails, all of them are.
 */
class CheckPreviewErrors implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait after the turn, so builds it started finish and the open preview has reloaded. */
    public const DELAY_SECONDS = 8;

    /** Below this, Jev counts an error as noise. Kept low: a real error left unsent leaves the app broken. */
    public const REAL_THRESHOLD = 0.4;

    /** Most errors Jev is asked about (the newest). */
    public const MAX_TRIAGED = 10;

    public int $timeout = 90;

    /**
     * @param  int  $since  When the turn ended (Unix ms).
     * @param  int|null  $turn  The user message that turn answered.
     */
    public function __construct(public Project $project, public int $since, public ?int $turn) {}

    /**
     * Check the preview after the turn that just ended, when the project has autofix on,
     * unless that turn was itself this check's request.
     */
    public static function afterTurn(Project $project): void
    {
        if (! $project->autofix) {
            return;
        }

        $turn = self::latestTurn($project);

        if ($turn !== null && Cache::get(self::requestKey($project)) === $turn) {
            return;
        }

        self::dispatch($project, now()->getTimestampMs(), $turn)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(PreviewErrors $errors, AgentQueue $queue, Jev $jev): void
    {
        $project = $this->project->fresh();
        $sandbox = $project?->sandbox;

        // Autofix turned off, busy again, or another turn has ended since (it gets its own check).
        if ($project === null || ! $project->autofix || $project->status === ProjectStatus::Working || self::latestTurn($project) !== $this->turn) {
            return;
        }

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return;
        }

        try {
            $found = $errors->check($sandbox, $this->since);
        } catch (SandboxException $e) {
            report($e);

            return;
        }

        $found = $this->worthFixing($jev, $project, $found);

        if ($found === []) {
            return;
        }

        $message = $queue->send($project, PreviewErrors::request($found));

        Cache::put(self::requestKey($project), $message->id, now()->addDay());
    }

    /**
     * The errors Jev judges real problems in the app; all of them when there's no key or Jev fails.
     *
     * @param  list<array<mixed>>  $found
     * @return list<array<mixed>>
     */
    protected function worthFixing(Jev $jev, Project $project, array $found): array
    {
        $found = array_slice($found, -self::MAX_TRIAGED);

        if ($found === [] || ($endpoint = $jev->endpointFor($project)) === null) {
            return $found;
        }

        $questions = [];

        foreach (array_keys($found) as $i) {
            $questions["error_{$i}"] = [
                'instructions' => "Is errors.error_{$i} a real problem in the app that needs fixing?",
                'true' => "It's a bug or a broken setup in the app itself that people using it would hit, likely caused by the last turn's changes or still broken after them.",
                'false' => "It's noise: a passing error while the dev server rebuilt or restarted (hot reload), a browser extension's, a missing favicon or source map, a dev-only warning, or something outside the app's code.",
            ];
        }

        try {
            $answers = $jev->yesOrNo($endpoint, $this->state($project, $found), $questions);
        } catch (Throwable $e) {
            report($e);

            return $found;
        }

        return array_values(array_filter($found, fn (int $i) => $answers["error_{$i}"] >= self::REAL_THRESHOLD, ARRAY_FILTER_USE_KEY));
    }

    /**
     * What Jev sees: the turn's request, what the agent did, and each error with its stack and when it happened.
     *
     * @param  list<array<mixed>>  $found
     * @return array<string, mixed>
     */
    protected function state(Project $project, array $found): array
    {
        $since = $project->messages()->where('id', '>', $this->turn ?? 0);

        return [
            'user_request' => Str::limit($this->turn === null ? '' : ($project->messages()->whereKey($this->turn)->value('content') ?? ''), 2000),
            'agent_actions' => (clone $since)->where('role', MessageRole::Activity)->pluck('content')
                ->reject(fn (string $action) => $action === 'Thinking')
                ->map(fn (string $action) => Str::limit($action, 150))
                ->take(-40)->values()->all(),
            'errors' => collect($found)->mapWithKeys(fn (array $error, int $i) => ["error_{$i}" => array_filter([
                'error' => PreviewErrors::describe($error),
                'source' => isset($error['src']) ? Str::limit((string) $error['src'], 200) : null,
                'stack' => isset($error['stack']) ? Str::limit((string) $error['stack'], 800) : null,
                'seconds_after_turn' => isset($error['t']) ? round(((int) $error['t'] - $this->since) / 1000, 1) : null,
            ], fn ($value) => $value !== null)])->all(),
        ];
    }

    protected static function latestTurn(Project $project): ?int
    {
        return $project->messages()->where('role', MessageRole::User)->reorder()->latest('id')->value('id');
    }

    protected static function requestKey(Project $project): string
    {
        return "preview-errors-request:{$project->id}";
    }
}
