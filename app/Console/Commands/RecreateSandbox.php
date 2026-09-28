<?php

namespace App\Console\Commands;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:recreate {project : Project id} {--keep-files : Copy the workspace, App Storage and agent history into the new sandbox}')]
#[Description("Replace a project's sandbox with a fresh one, e.g. after rebuilding the image.")]
class RecreateSandbox extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SandboxUpdater $updater): int
    {
        $project = Project::findOrFail($this->argument('project'));

        $sandbox = $updater->recreate($project, (bool) $this->option('keep-files'), fn (string $message) => $this->components->info($message));

        $this->components->info("Sandbox {$sandbox->status->value}: ".($sandbox->preview_url ?? $sandbox->error ?? '-'));

        return $sandbox->status === SandboxStatus::Running ? self::SUCCESS : self::FAILURE;
    }
}
