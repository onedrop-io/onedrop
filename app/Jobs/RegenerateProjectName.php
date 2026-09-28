<?php

namespace App\Jobs;

use App\Models\Project;
use App\Sandbox\Agents\ChatGptSignInFailed;
use App\Sandbox\Agents\ProjectNamer;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RegenerateProjectName implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A one-off model call; it shouldn't take long. */
    public int $timeout = 120;

    public int $uniqueFor = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project) {}

    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    /**
     * Ask the project's AI for a new title and use it (without moving the project in "Recent").
     */
    public function handle(ProjectNamer $namer): void
    {
        try {
            $name = $namer->suggest($this->project);
            Project::withoutTimestamps(fn () => $this->project->update(['name' => $name]));
        } catch (SandboxException|ChatGptSignInFailed $e) {
            report($e);
        } finally {
            ProjectNamer::doneNaming($this->project);
        }
    }
}
