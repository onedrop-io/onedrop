<?php

namespace App\Actions;

use App\Jobs\DestroySandbox;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\Domains\ProjectDomains;
use App\Sandbox\Hosting\HostedServices;
use App\Sandbox\ProjectBackups;
use App\Sandbox\ProjectIcons;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\Publishing\PublishException;
use App\Sandbox\ShareCards;
use Illuminate\Support\Facades\Storage;

class DeleteProject
{
    public function __construct(protected Publishers $publishers, protected ProjectBackups $backups, protected ProjectSnapshots $snapshots, protected ProjectIcons $icons, protected ShareCards $shareCards, protected HostedServices $hosted, protected ProjectDomains $domains) {}

    /**
     * Delete the project: take its app offline, then remove its chat, attachments, code backup, snapshots, icon, share page,
     * sandbox, its custom domains, and everything made for it at hosting providers (HOST-002, DOM-004).
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

        // Custom domains first: Fly certificates live in the Fly app destroyAll deletes (DOM-004).
        $this->domains->removeAll($project);
        $this->hosted->destroyAll($project);

        // The main sandbox and every task's copy of the app (TASK-003).
        $sandboxes = $project->sandboxes()->get();

        $project->delete();
        Storage::disk(Attachment::disk())->deleteDirectory("attachments/{$project->id}");
        $this->backups->delete($project);
        $this->snapshots->delete($project);
        $this->icons->delete($project);
        $this->shareCards->delete($project->id);

        foreach ($sandboxes as $sandbox) {
            if ($sandbox->external_id) {
                DestroySandbox::dispatch($sandbox->external_id, $sandbox->provider);
            }
        }
    }
}
