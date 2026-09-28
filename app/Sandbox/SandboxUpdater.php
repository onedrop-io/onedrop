<?php

namespace App\Sandbox;

use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\PublishProject;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Publishing\Publisher;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Replaces a project's sandbox with a fresh one from the current image, optionally keeping its files,
 * so existing projects get new guides, tools and proxy changes (sandbox:recreate, sandbox:update, UpdateSandbox).
 */
class SandboxUpdater
{
    /**
     * Paths kept across an update: the app, its App Storage buckets, and the sandbox user's home, which holds
     * OpenCode's sessions (so the agent remembers) and anything the agent installed there (e.g. a local database).
     */
    public const KEPT_PATHS = ['/workspace', '/data/storage', '/home/sandbox'];

    /** Longest an update may hold its lock, in seconds (copying a large workspace takes a while). */
    protected const LOCK_SECONDS = 900;

    public function __construct(protected SandboxProvider $provider, protected Publisher $publisher) {}

    /**
     * Whether the project's running sandbox was made from an older image.
     *
     * @throws SandboxException
     */
    public function isOutdated(Project $project): bool
    {
        $sandbox = $project->sandbox()->first();

        return $sandbox?->status === SandboxStatus::Running
            && $sandbox->external_id !== null
            && $this->provider->isOutdated($sandbox->external_id);
    }

    /**
     * Move an outdated sandbox to the current image, keeping its files. Waits for an update already running
     * for the same project instead of starting a second one. Returns whether this call updated it.
     *
     * @throws SandboxException
     */
    public function updateIfOutdated(Project $project): bool
    {
        try {
            return Cache::lock($this->lockName($project), self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($project) {
                if (! $this->isOutdated($project)) {
                    return false;
                }

                self::markUpdating($project);

                return $this->replace($project, keepFiles: true)->status === SandboxStatus::Running;
            });
        } finally {
            self::doneUpdating($project);
        }
    }

    /**
     * Show the workspace that the sandbox is being updated (it polls until it's done).
     */
    public static function markUpdating(Project $project): void
    {
        Cache::put("sandbox-updating:{$project->id}", true, self::LOCK_SECONDS);
    }

    public static function doneUpdating(Project $project): void
    {
        Cache::forget("sandbox-updating:{$project->id}");
    }

    public static function isUpdating(Project $project): bool
    {
        return Cache::has("sandbox-updating:{$project->id}");
    }

    /**
     * Replace the project's sandbox with a fresh one, under the project's update lock.
     *
     * @param  (Closure(string): void)|null  $report  progress messages
     *
     * @throws SandboxException
     */
    public function recreate(Project $project, bool $keepFiles = false, ?Closure $report = null): Sandbox
    {
        return Cache::lock($this->lockName($project), self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, fn () => $this->replace($project, $keepFiles, $report));
    }

    /**
     * @param  (Closure(string): void)|null  $report
     *
     * @throws SandboxException
     */
    protected function replace(Project $project, bool $keepFiles, ?Closure $report = null): Sandbox
    {
        $report ??= fn (string $message) => null;
        $project->refresh();
        $old = $project->sandbox?->external_id;
        $backup = $keepFiles && $old ? $this->backup($old) : null;

        if ($backup) {
            $report('Copied files out of the old sandbox.');
        }

        try {
            if ($old) {
                $this->provider->destroy($old);
            }

            // The publish sidecar shares the old sandbox's network, so it goes too; republished below.
            $republishAs = $project->publish_status === PublishStatus::Live ? $project->publish_visibility : null;

            if ($project->publish_status) {
                $this->publisher->stop($project);
                $project->update(['publish_status' => null, 'published_url' => null]);
            }

            $project->sandbox()->updateOrCreate([], [
                'provider' => config('sandbox.provider'),
                'external_id' => null,
                'status' => SandboxStatus::Creating,
                'preview_url' => null,
                'shell_url' => null,
                'ssh_address' => null,
                'error' => null,
            ]);

            if (! $backup) {
                $project->update(['agent_session_id' => null]);
            }

            CreateSandbox::dispatchSync($project);
            $sandbox = $project->sandbox()->firstOrFail();

            if ($backup && $sandbox->status === SandboxStatus::Running) {
                foreach (self::KEPT_PATHS as $index => $path) {
                    $this->provider->copyIn($sandbox->external_id, "{$backup}/{$index}", $path);
                }

                // The app's dev server (.zap/dev) arrived with the files; start it.
                $this->provider->exec($sandbox->external_id, ['/opt/zap/restart']);
                $report('Copied files into the new sandbox.');
            }

            if ($republishAs && $sandbox->status === SandboxStatus::Running) {
                $project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => $republishAs, 'publish_error' => null]);
                PublishProject::dispatch($project);
                $report('Publishing it again.');
            }

            return $sandbox;
        } finally {
            if ($backup) {
                File::deleteDirectory($backup);
            }
        }
    }

    /**
     * Stop the old sandbox (so files a database is writing are at rest) and copy the kept paths out of it
     * into a temporary directory. If copying fails, the old sandbox starts again, untouched.
     *
     * @throws SandboxException
     */
    protected function backup(string $id): string
    {
        $directory = storage_path('framework/sandbox-backup-'.uniqid());
        $this->provider->pause($id);

        try {
            foreach (self::KEPT_PATHS as $index => $path) {
                File::ensureDirectoryExists("{$directory}/{$index}");
                $this->provider->copyOut($id, $path, "{$directory}/{$index}");
            }
        } catch (SandboxException $e) {
            File::deleteDirectory($directory);
            $this->provider->start($id);

            throw $e;
        }

        return $directory;
    }

    protected function lockName(Project $project): string
    {
        return "sandbox-update:{$project->id}";
    }
}
