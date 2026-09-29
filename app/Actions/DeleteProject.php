<?php

namespace App\Actions;

use App\Jobs\DestroySandbox;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\ProjectBackups;
use App\Sandbox\ProjectIcons;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\Publishing\PublishException;
use Illuminate\Support\Facades\Storage;

class DeleteProject
{
    public function __construct(protected Publishers $publishers, protected ProjectBackups $backups, protected ProjectIcons $icons) {}

    /**
     * Delete the project: take its app offline, then remove its chat, attachments, code backup, icon, and sandbox.
     */
    public function handle(Project $project): void
    {
        if ($project->publish_status) {
            try {
                $this->publishers->forProject($project)->stop($project);
            } catch (PublishException $e) {
                report($e);
            }
        }

        // The main sandbox and every task's copy of the app (TASK-003).
        $sandboxes = $project->sandboxes()->get();

        $project->delete();
        Storage::disk(Attachment::DISK)->deleteDirectory("attachments/{$project->id}");
        $this->backups->delete($project);
        $this->icons->delete($project);

        foreach ($sandboxes as $sandbox) {
            if ($sandbox->external_id) {
                DestroySandbox::dispatch($sandbox->external_id, $sandbox->provider);
            }
        }
    }
}
