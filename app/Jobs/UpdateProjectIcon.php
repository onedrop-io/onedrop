<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\Agents\ChatGptSignInFailed;
use App\Sandbox\ProjectIcons;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateProjectIcon implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Reading the favicon is quick; drawing one is a single model call. */
    public int $timeout = 120;

    public int $uniqueFor = 120;

    /**
     * @param  bool  $redraw  Draw a new icon even if the app has one.
     */
    public function __construct(public Project $project, public bool $redraw = false) {}

    public function uniqueId(): string
    {
        return $this->project->id.($this->redraw ? ':redraw' : '');
    }

    /**
     * Pick up the app's favicon (or draw one), or draw a new one when asked.
     */
    public function handle(ProjectIcons $icons): void
    {
        try {
            $this->redraw ? $icons->draw($this->project) : $icons->sync($this->project);
        } catch (SandboxException|ChatGptSignInFailed $e) {
            report($e);
        } finally {
            ProjectIcons::doneDrawing($this->project);
        }
    }
}
