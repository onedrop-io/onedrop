<?php

namespace App\Jobs;

use App\Enums\GitSyncStatus;
use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncGitRemote implements ShouldQueue
{
    use Queueable;

    /** Long enough for a large repository's fetch on the platform (GitRemote::FETCH_TIMEOUT) and copying it in. */
    public int $timeout = 1500;

    /** Any error other than waiting for another sync of the project ends it. */
    public int $maxExceptions = 1;

    /**
     * @param  int  $check  0 for the sync itself, 1..LAST_CHECK for a safety check, CALLED_BACK when the sandbox said its fetch is done
     */
    public function __construct(public Project $project, public GitSyncStatus $direction, public ?string $branch = null, public int $check = 0) {}

    /**
     * Waiting for another sync of the project (WithoutOverlapping) makes a few attempts.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("git-remote:{$this->project->id}"))->releaseAfter(10)->expireAfter($this->timeout)];
    }

    /**
     * Push the current branch to the project's remote, or pull its new commits ($branch, for a project with no
     * commits yet), and record how it went.
     */
    public function handle(GitRemote $remote): void
    {
        $project = $this->project->fresh() ?? $this->project;

        // A safety check or the sandbox's call, after the pull was already brought in (or failed).
        if ($this->check > 0 && $project->git_sync_status !== $this->direction) {
            return;
        }

        try {
            // A pull the sandbox fetches itself says when it's done (SandboxWorkController queues this again); a
            // safety check after 15 and 30 minutes catches a call that never arrives.
            if ($this->direction === GitSyncStatus::Pulling && ! $remote->pullStep($project, $this->branch, ProjectSnapshots::callbackUrl('sandbox-events.fetched', array_filter(['project' => $project, 'job' => 'sync', 'branch' => $this->branch])))) {
                if ($this->check < ImportRepository::LAST_CHECK) {
                    self::dispatch($project, $this->direction, $this->branch, $this->check + 1)->delay(now()->addMinutes(ImportRepository::CHECK_MINUTES));
                } elseif ($this->check === ImportRepository::LAST_CHECK) {
                    throw new GitException(__('The download didn\'t finish in time. Try again.'));
                }

                return;
            }

            if ($this->direction === GitSyncStatus::Pushing) {
                $remote->push($project);
            }

            $project->update(['git_sync_status' => null, 'git_sync_error' => null, 'git_synced_at' => now()]);
        } catch (GitException|SandboxException $e) {
            $project->update(['git_sync_status' => GitSyncStatus::Failed, 'git_sync_error' => $e->getMessage()]);
        }
    }

    /**
     * Never leave the panel showing "Pushing…" if the job itself crashes.
     */
    public function failed(?Throwable $exception): void
    {
        $this->project->update(['git_sync_status' => GitSyncStatus::Failed, 'git_sync_error' => __('Something went wrong. Try again.')]);
    }
}
