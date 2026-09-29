<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\PreviewErrors;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * After an agent turn, look for new errors in the preview and send them to the agent, once (ERR-001).
 */
class CheckPreviewErrors implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait after the turn, so builds it started finish and the open preview has reloaded. */
    public const DELAY_SECONDS = 8;

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

    public function handle(PreviewErrors $errors, AgentQueue $queue): void
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

        if ($found === []) {
            return;
        }

        $message = $queue->send($project, PreviewErrors::request($found));

        Cache::put(self::requestKey($project), $message->id, now()->addDay());
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
