<?php

namespace App\Console\Commands;

use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\PublishProject;
use App\Models\Project;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('sandbox:recreate {project : Project id} {--keep-files : Copy the workspace and agent history into the new sandbox (Docker only)}')]
#[Description("Replace a project's sandbox with a fresh one, e.g. after rebuilding the image.")]
class RecreateSandbox extends Command
{
    /**
     * Paths carried over by --keep-files: the app, and OpenCode's sessions (so the agent remembers).
     */
    public const KEPT_PATHS = ['/workspace', '/home/sandbox/.local/share/opencode'];

    /**
     * Execute the console command.
     */
    public function handle(SandboxProvider $provider): int
    {
        $project = Project::findOrFail($this->argument('project'));
        $old = $project->sandbox?->external_id;
        $keep = $this->option('keep-files') && $old;

        if ($keep && config('sandbox.provider') !== 'docker') {
            $this->components->error('--keep-files only works with the Docker provider.');

            return self::FAILURE;
        }

        $backup = $keep ? $this->backup($old) : null;

        if ($old) {
            $provider->destroy($old);
        }

        // The publish sidecar shares the old sandbox's network, so it goes too; republished below.
        $republishAs = $project->publish_status === PublishStatus::Live ? $project->publish_visibility : null;

        if ($project->publish_status) {
            app(Publisher::class)->stop($project);
            $project->update(['publish_status' => null, 'published_url' => null]);
        }

        $project->sandbox()->updateOrCreate([], [
            'provider' => config('sandbox.provider'),
            'external_id' => null,
            'status' => SandboxStatus::Creating,
            'preview_url' => null,
            'shell_url' => null,
            'error' => null,
        ]);

        if (! $keep) {
            $project->update(['agent_session_id' => null]);
        }

        CreateSandbox::dispatchSync($project);
        $sandbox = $project->sandbox()->first();

        if ($backup && $sandbox->status === SandboxStatus::Running) {
            $this->restore($backup, $sandbox->external_id);
            $provider->exec($sandbox->external_id, ['/opt/zap/restart']);
        }

        if ($backup) {
            File::deleteDirectory($backup);
        }

        if ($republishAs && $sandbox->status === SandboxStatus::Running) {
            $project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => $republishAs, 'publish_error' => null]);
            PublishProject::dispatch($project);
            $this->components->info('Publishing it again.');
        }

        $this->components->info("Sandbox {$sandbox->status->value}: ".($sandbox->preview_url ?? $sandbox->error ?? '-'));

        return $sandbox->status === SandboxStatus::Running ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Copy kept paths out of the old container into a temp directory.
     */
    protected function backup(string $container): string
    {
        $dir = storage_path('framework/sandbox-backup-'.uniqid());

        foreach (self::KEPT_PATHS as $index => $path) {
            File::ensureDirectoryExists("{$dir}/{$index}");
            Process::forever()->run(['docker', 'cp', "{$container}:{$path}/.", "{$dir}/{$index}"]);
        }

        $this->components->info('Copied files out of the old sandbox.');

        return $dir;
    }

    /**
     * Copy kept paths into the new container, owned by the sandbox user.
     */
    protected function restore(string $dir, string $container): void
    {
        foreach (self::KEPT_PATHS as $index => $path) {
            Process::run(['docker', 'exec', '-u', 'root', $container, 'mkdir', '-p', $path]);
            Process::forever()->run(['docker', 'cp', "{$dir}/{$index}/.", "{$container}:{$path}"]);
        }

        // mkdir -p above runs as root, so hand back every created parent (e.g. ~/.local) too.
        Process::run(['docker', 'exec', '-u', 'root', $container, 'chown', '-R', 'sandbox:sandbox', '/workspace', '/home/sandbox']);

        $this->components->info('Copied files into the new sandbox.');
    }
}
