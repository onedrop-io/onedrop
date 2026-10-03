<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\Hosting\ReleaseStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteDatabaseCopy implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $projectId, public string $path) {}

    /**
     * Delete a downloaded database copy (HOST-007) once its link has expired: it's the app's data.
     */
    public function handle(ReleaseStorage $releases): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project !== null) {
            $releases->disk($project)->delete($this->path);
        }
    }
}
