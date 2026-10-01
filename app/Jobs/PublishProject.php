<?php

namespace App\Jobs;

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Models\Project;
use App\Sandbox\AbuseCheck;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\Publishing\PublishException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishProject implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    /**
     * On the hosted install, check a public app for abuse first (PUB-003): one that looks like it waits for a
     * platform admin instead. Then start the endpoint, and let ConfirmPublication wait for it to come up.
     */
    public function handle(Publishers $publishers, AbuseCheck $abuse): void
    {
        if ($this->project->publish_visibility === PublishVisibility::Public && $abuse->enabled()
            && $abuse->run($this->project, 'publish')->blocksPublic()) {
            return;
        }

        try {
            $publishers->forProject($this->project)->start($this->project);
        } catch (PublishException $e) {
            $this->project->update(['publish_status' => PublishStatus::Failed, 'publish_error' => $e->getMessage()]);

            return;
        }

        ConfirmPublication::dispatch($this->project)->delay(now()->addSeconds(2));
    }

    /**
     * Something crashed while starting (e.g. a command timed out): fail visibly rather than stay "Publishing…" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $project = $this->project->fresh();

        if ($project?->publish_status !== PublishStatus::Publishing) {
            return;
        }

        $project->update([
            'publish_status' => PublishStatus::Failed,
            'publish_error' => 'Publishing stopped unexpectedly. Try again.',
        ]);
    }
}
