<?php

namespace App\Jobs;

use App\Enums\GitSyncStatus;
use App\Models\Project;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
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

    /** Any error other than checking back (or waiting for another sync of the project) ends it. */
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public GitSyncStatus $direction, public ?string $branch = null) {}

    /**
     * A pull the sandbox fetches itself is checked on every few seconds, each check a short attempt; give up once a
     * fetch couldn't still be running.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(GitRemote::FETCH_TIMEOUT + 600);
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

        try {
            if ($this->direction === GitSyncStatus::Pulling && ! $remote->pullStep($project, $this->branch)) {
                $this->release(ImportRepository::CHECK_SECONDS);

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
