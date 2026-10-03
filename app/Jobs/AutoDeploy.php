<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\SandboxStatus;
use App\Enums\TurnOutcome;
use App\Models\Project;
use App\Sandbox\Hosting\HostingChanges;
use App\Sandbox\PreviewErrors;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Updates a hosted app by itself after a turn that went well, when its project has auto-deploy on (HOST-006): the
 * agent finished (it isn't waiting on the user or working again), the preview showed no new errors, and the sandbox
 * has changes the hosted app doesn't. Otherwise it waits for the next turn.
 */
class AutoDeploy implements ShouldQueue
{
    use Queueable;

    /** Seconds after the turn: the preview check (CheckPreviewErrors) and the backup that reads the changes go first. */
    public const DELAY_SECONDS = 60;

    /**
     * @param  int  $since  When the turn ended (Unix ms).
     * @param  int|null  $turn  The user message that turn answered.
     */
    public function __construct(public Project $project, public int $since, public ?int $turn) {}

    /**
     * Check once the turn has settled, when the project is hosted with auto-deploy on.
     */
    public static function afterTurn(Project $project): void
    {
        if (! $project->auto_deploy || $project->publish_target !== PublishTarget::Hosting || $project->publish_status !== PublishStatus::Live) {
            return;
        }

        self::dispatch($project, now()->getTimestampMs(), self::latestTurn($project))->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(PreviewErrors $errors, HostingChanges $changes): void
    {
        $project = $this->project->fresh();
        $sandbox = $project?->sandbox;

        if ($project === null || ! $this->stillDue($project) || $sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return;
        }

        try {
            if ($errors->check($sandbox, $this->since) !== []) {
                return;
            }

            $changes->refresh($project);
        } catch (SandboxException $e) {
            report($e);

            return;
        }

        if (($project->fresh()?->hosting_changes['count'] ?? 0) === 0) {
            return;
        }

        $project->update(['publish_status' => PublishStatus::Publishing, 'publish_error' => null]);
        PublishProject::dispatch($project);
    }

    /**
     * Still hosted with auto-deploy on, idle since this turn, done rather than waiting on the user, and not deploying.
     */
    protected function stillDue(Project $project): bool
    {
        return $project->auto_deploy
            && $project->publish_target === PublishTarget::Hosting
            && $project->publish_status === PublishStatus::Live
            && $project->status !== ProjectStatus::Working
            && self::latestTurn($project) === $this->turn
            && in_array($project->turn_outcome, [null, TurnOutcome::Done], true)
            && ! $project->deployments()->where('status', DeploymentStatus::Running)->exists();
    }

    protected static function latestTurn(Project $project): ?int
    {
        return $project->messages()->where('role', MessageRole::User)->reorder()->latest('id')->value('id');
    }
}
