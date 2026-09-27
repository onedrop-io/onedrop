<?php

namespace App\Jobs;

use App\Enums\PublishStatus;
use App\Models\Project;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\PublishException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishProject implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    /**
     * Start the endpoint, then let ConfirmPublication wait for it to come up.
     */
    public function handle(Publisher $publisher): void
    {
        try {
            $publisher->start($this->project);
        } catch (PublishException $e) {
            $this->project->update(['publish_status' => PublishStatus::Failed, 'publish_error' => $e->getMessage()]);

            return;
        }

        ConfirmPublication::dispatch($this->project)->delay(now()->addSeconds(2));
    }
}
