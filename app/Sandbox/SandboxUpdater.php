<?php

namespace App\Sandbox;

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\PublishProject;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Publishing\Publisher;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Replaces a project's sandbox with a fresh one from the current image, optionally keeping its files
 * (without them, the code comes back from its backup; see ProjectBackups), so existing projects get new guides, tools and proxy changes (sandbox:recreate, sandbox:update, UpdateSandbox).
 */
class SandboxUpdater
{
    /**
     * Paths kept across an update: the app, its App Storage buckets, and the sandbox user's home, which holds
     * OpenCode's sessions (so the agent remembers) and anything the agent installed there (e.g. a local database).
     */
    public const KEPT_PATHS = ['/workspace', '/data/storage', '/home/sandbox'];

    /**
     * Hands a carried-over home folder back to the image's shell setup in /opt/zap: an old ~/.bashrc that doesn't load
     * /opt/zap/bashrc gives way to one that does, and the image's old copy of the prompt config (marked by its header) goes.
     */
    public const USE_IMAGE_SHELL_SETUP = 'grep -qF /opt/zap/bashrc ~/.bashrc 2>/dev/null || cp /opt/zap/home-bashrc ~/.bashrc; '
        .'if grep -qF "# Shell tab prompt (starship)" ~/.config/starship.toml 2>/dev/null; then rm ~/.config/starship.toml; fi';

    /** Longest an update may hold its lock, in seconds (copying a large workspace takes a while). */
    protected const LOCK_SECONDS = 900;

    public function __construct(protected SandboxProvider $provider, protected Publisher $publisher, protected ProjectBackups $backups) {}

    /**
     * Whether the project's running sandbox was made from an older image, or lives on another provider than the configured one.
     *
     * @throws SandboxException
     */
    public function isOutdated(Project $project): bool
    {
        $sandbox = $project->sandbox()->first();

        // On another provider than the configured one (SANDBOX_PROVIDER changed): move it there.
        return $sandbox?->status === SandboxStatus::Running
            && $sandbox->external_id !== null
            && ($sandbox->provider !== config('sandbox.provider') || $this->provider->isOutdated($sandbox->external_id));
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
        $previous = $project->sandbox?->only(['provider', 'external_id', 'status', 'preview_url', 'shell_url', 'ssh_address', 'error']);
        $old = $previous['external_id'] ?? null;
        $backup = $keepFiles && $old ? $this->backup($old) : null;
        $republishAs = $project->publish_status === PublishStatus::Live ? $project->publish_visibility : null;
        $sandbox = null;

        if ($backup) {
            $report('Copied files out of the old sandbox.');
        }

        try {
            // With nothing to keep the old sandbox can go now. Otherwise it stays until the new one has every file,
            // so an update that fails or is cut off (a deploy, a queue timeout) never loses them.
            if ($old && ! $backup) {
                $this->provider->destroy($old);
            }

            // The publish sidecar shares the old sandbox's network, so it goes too; republished below.
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
                $project->tasks()->reorder()->update(['agent_session_id' => null]);
            }

            CreateSandbox::dispatchSync($project);
            $sandbox = $project->sandbox()->firstOrFail();

            if ($backup) {
                if ($sandbox->status !== SandboxStatus::Running) {
                    throw new SandboxException($sandbox->error ?: "The new sandbox didn't start.");
                }

                foreach (self::KEPT_PATHS as $index => $path) {
                    $this->provider->copyIn($sandbox->external_id, "{$backup}/{$index}", $path);
                }

                // The old home folder brought its shell files; older ones held the whole setup, so hand it back to the image's.
                $this->provider->exec($sandbox->external_id, ['bash', '-c', self::USE_IMAGE_SHELL_SETUP]);

                // The app's dev server (.zap/dev) arrived with the files; start it.
                $this->provider->exec($sandbox->external_id, ['/opt/zap/restart']);
                $report('Copied files into the new sandbox.');

                $this->provider->destroy($old);
            }

            // Without the old sandbox's files, the code comes back from its backup.
            if (! $backup) {
                $this->restoreCode($project, $sandbox, $report);
            }

            $this->republish($project, $republishAs, $sandbox, $report);

            return $sandbox;
        } catch (Throwable $e) {
            if ($backup) {
                $this->keepOld($project, $previous, $sandbox);
                $this->republish($project, $republishAs, $project->sandbox()->firstOrFail(), $report);
            }

            throw $e;
        } finally {
            if ($backup) {
                File::deleteDirectory($backup);
            }
        }
    }

    /**
     * An update that didn't finish: drop the new sandbox and go back to the old one, which still has every file.
     *
     * @param  array<string, mixed>  $previous  the old sandbox's record
     */
    protected function keepOld(Project $project, array $previous, ?Sandbox $new): void
    {
        if ($new?->external_id && $new->external_id !== $previous['external_id']) {
            try {
                $this->provider->destroy($new->external_id);
            } catch (SandboxException $e) {
                report($e);
            }
        }

        $project->sandbox()->updateOrCreate([], $previous);

        try {
            $this->provider->start($previous['external_id']);
        } catch (SandboxException $e) {
            report($e);
        }
    }

    /**
     * Clone the project's backed-up code into the new sandbox. A backup that can't be restored doesn't undo the
     * replacement; it's reported, and stays on the backup disk to try again.
     *
     * @param  (Closure(string): void)  $report
     */
    protected function restoreCode(Project $project, Sandbox $sandbox, Closure $report): void
    {
        try {
            if ($this->backups->restore($project, $sandbox)) {
                $report('Restored the code from its backup.');
            }
        } catch (SandboxException $e) {
            report($e);
            $report("Couldn't restore the code from its backup: {$e->getMessage()}");
        }
    }

    /**
     * @param  (Closure(string): void)  $report
     */
    protected function republish(Project $project, ?PublishVisibility $visibility, Sandbox $sandbox, Closure $report): void
    {
        if ($visibility && $sandbox->status === SandboxStatus::Running) {
            $project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => $visibility, 'publish_error' => null]);
            PublishProject::dispatch($project);
            $report('Publishing it again.');
        }
    }

    /**
     * Stop the old sandbox (so files a database is writing are at rest) and copy the kept paths out of it
     * into a temporary directory. If copying fails, the old sandbox starts again, untouched.
     *
     * @throws SandboxException
     * @throws Throwable
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
        } catch (Throwable $e) {
            // Any failure (a lost connection, a full disk), not just the provider's own errors.
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
