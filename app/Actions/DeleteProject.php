<?php

namespace App\Actions;

use App\Jobs\DestroySandbox;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\PublishException;
use Illuminate\Support\Facades\Storage;

class DeleteProject
{
    public function __construct(protected Publisher $publisher) {}

    /**
     * Delete the project: take its app offline, then remove its chat, attachments, and sandbox.
     */
    public function handle(Project $project): void
    {
        if ($project->publish_status) {
            try {
                $this->publisher->stop($project);
            } catch (PublishException $e) {
                report($e);
            }
        }

        $sandbox = $project->sandbox;

        $project->delete();
        Storage::disk(Attachment::DISK)->deleteDirectory("attachments/{$project->id}");

        if ($sandbox?->external_id) {
            DestroySandbox::dispatch($sandbox->external_id, $sandbox->provider);
        }
    }
}
