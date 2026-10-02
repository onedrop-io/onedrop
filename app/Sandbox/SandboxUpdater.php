<?php

namespace App\Sandbox;

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\PublishProject;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use App\Sandbox\Publishing\Publishers;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Keeps existing projects' sandboxes current (SBX-002): copies changed tool files into a running sandbox (SandboxTools),
 * or replaces it with a fresh one from the current image, optionally keeping its files (without them, the code comes
 * back from its backup; see ProjectBackups). Used by sandbox:recreate, sandbox:update, UpdateSandbox and agent runs.
 */
class SandboxUpdater
{
    /**
     * Paths kept across an update: the app, its App Storage buckets, and the sandbox user's home, which holds
     * OpenCode's sessions (so the agent remembers) and anything the agent installed there (e.g. a local database).
     */
    public const KEPT_PATHS = ['/workspace', '/data/storage', '/home/sandbox'];

    /**
     * Hands a carried-over home folder back to the image's shell setup in /opt/onedrop: an old ~/.bashrc that doesn't load
     * /opt/onedrop/bashrc gives way to one that does, and the image's old copy of the prompt config (marked by its header) goes.
     */
    public const USE_IMAGE_SHELL_SETUP = 'grep -qF /opt/onedrop/bashrc ~/.bashrc 2>/dev/null || cp /opt/onedrop/home-bashrc ~/.bashrc; '
        .'if grep -qF "# Shell tab prompt (starship)" ~/.config/starship.toml 2>/dev/null; then rm ~/.config/starship.toml; fi';

    /** Longest an update may hold its lock, in seconds (copying a large workspace takes a while). */
    protected const LOCK_SECONDS = 900;

    public function __construct(protected SandboxProvider $provider, protected Publishers $publishers, protected ProjectBackups $backups, protected SandboxTools $tools, protected ProjectSnapshots $snapshots) {}

    /**
     * Whether the project's running sandbox needs a new sandbox to be current: it lives on another provider than the
     * configured one, or a newer image changed what its tools can't bring in place (see bringUpToDate()).
     *
     * @throws SandboxException
     */
    public function isOutdated(Project $project): bool
    {
        $sandbox = $this->runningSandbox($project);

        return $sandbox !== null && $this->plan($sandbox) === 'rebuild';
    }

    /**
     * Bring an outdated sandbox up to date: copy in changed tool files, or, when those can't do it, move it to a new
     * sandbox from the current image, keeping its files. With $rebuild false only tool files are copied in (seconds),
     * for someone waiting on it. Waits for an update already running for the same project instead of starting a
     * second one. Returns whether this call changed the sandbox.
     *
     * @throws SandboxException
     */
    public function updateIfOutdated(Project $project, bool $rebuild = true): bool
    {
        return Cache::lock($this->lockName($project), self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($project, $rebuild) {
            $sandbox = $this->runningSandbox($project);
            $plan = $sandbox ? $this->plan($sandbox) : null;

            if (is_array($plan)) {
                return $this->tools->install($this->provider, $sandbox->external_id, $plan) || ($rebuild && $this->provider->isOutdated($sandbox->external_id) && $this->rebuild($project));
            }

            return $plan === 'rebuild' && $rebuild && $this->rebuild($project);
        });
    }

    /**
     * What a sandbox needs: the tool files to copy in, 'rebuild' for a new sandbox, or null when it's current. Tool files
     * go in only over the base they were written for; a sandbox on an older base waits for a newer image (a new
     * sandbox) rather than getting scripts that may call what its base doesn't have.
     *
     * @return list<string>|'rebuild'|null
     *
     * @throws SandboxException
     */
    protected function plan(Sandbox $sandbox): array|string|null
    {
        if ($sandbox->provider !== config('sandbox.provider')) {
            return 'rebuild';
        }

        if ($this->tools->available()) {
            ['base' => $base, 'changed' => $changed] = $this->tools->compare($this->provider, $sandbox->external_id);

            if ($base) {
                return $changed ?: null;
            }
        }

        return $this->provider->isOutdated($sandbox->external_id) ? 'rebuild' : null;
    }

    /**
     * @throws SandboxException
     */
    protected function rebuild(Project $project): bool
    {
        self::markUpdating($project);

        try {
            return $this->replace($project, keepFiles: true)->status === SandboxStatus::Running;
        } finally {
            self::doneUpdating($project);
        }
    }

    protected function runningSandbox(Project $project): ?Sandbox
    {
        $sandbox = $project->sandbox()->first();

        return $sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null ? $sandbox : null;
    }

    /**
     * Show the workspace that a new sandbox is being made for it (it polls until it's done).
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
     * Replace the project's sandbox with a fresh one, under the project's update lock. With $from, the new sandbox
     * gets that snapshot's files instead of the old sandbox's (sandbox:restore).
     *
     * @param  (Closure(string): void)|null  $report  progress messages
     *
     * @throws SandboxException
     */
    public function recreate(Project $project, bool $keepFiles = false, ?Closure $report = null, ?ProjectSnapshot $from = null): Sandbox
    {
        return Cache::lock($this->lockName($project), self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, fn () => $this->replace($project, $keepFiles, $report, $from));
    }

    /**
     * @param  (Closure(string): void)|null  $report
     *
     * @throws SandboxException
     */
    protected function replace(Project $project, bool $keepFiles, ?Closure $report = null, ?ProjectSnapshot $from = null): Sandbox
    {
        $report ??= fn (string $message) => null;
        $project->refresh();
        $previous = $project->sandbox?->only(['provider', 'external_id', 'status', 'preview_url', 'shell_url', 'ssh_address', 'error']);
        $old = $previous['external_id'] ?? null;
        // The files come from a snapshot when the new sandbox can reach one (SBX-009); otherwise they're copied
        // through the platform. Without the old sandbox, the latest snapshot is all there is.
        $snapshot = $from ?? ($keepFiles ? $this->snapshotToKeep($project, $old !== null) : null);
        $backup = $keepFiles && $old && ! $snapshot ? $this->backup($old) : null;
        // The old sandbox stays until the new one has every file, so an update that fails or is cut off (a deploy, a
        // queue timeout) never loses them.
        $keepsOld = $old && ($backup || $snapshot);
        $republishAs = $project->publish_status === PublishStatus::Live ? $project->publish_visibility : null;
        $sandbox = null;

        if ($backup) {
            $report('Copied files out of the old sandbox.');
        }

        try {
            if ($old && $snapshot) {
                // Nothing the old app does after its snapshot would reach the new sandbox: stop it.
                $this->provider->pause($old);

                if (! $from) {
                    $report('Saved a snapshot of the old sandbox.');
                }
            }

            if ($old && ! $keepsOld) {
                $this->provider->destroy($old);
            }

            // The publish sidecar shares the old sandbox's network, so it goes too; republished below.
            if ($project->publish_status) {
                $this->publishers->forProject($project)->stop($project);
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

            if (! $backup && ! $snapshot) {
                $project->update(['agent_session_id' => null]);
                $project->tasks()->reorder()->update(['agent_session_id' => null]);
            }

            CreateSandbox::dispatchSync($project);
            $sandbox = $project->sandbox()->firstOrFail();

            if ($backup || $snapshot) {
                if ($sandbox->status !== SandboxStatus::Running) {
                    throw new SandboxException($sandbox->error ?: "The new sandbox didn't start.");
                }

                if ($snapshot) {
                    if (! $this->snapshots->restore($project, $sandbox, $snapshot)) {
                        throw new SandboxException("The new sandbox couldn't get the project's snapshot.");
                    }
                } else {
                    foreach (self::KEPT_PATHS as $index => $path) {
                        $this->provider->copyIn($sandbox->external_id, "{$backup}/{$index}", $path);
                    }
                }

                // The old home folder brought its shell files; older ones held the whole setup, so hand it back to the image's.
                $this->provider->exec($sandbox->external_id, ['bash', '-c', self::USE_IMAGE_SHELL_SETUP]);

                // The app's dev server (.onedrop/dev) arrived with the files; start it.
                $this->provider->exec($sandbox->external_id, ['/opt/onedrop/restart']);
                $report($snapshot ? 'Restored the project\'s snapshot into the new sandbox.' : 'Copied files into the new sandbox.');

                if ($old) {
                    $this->provider->destroy($old);
                }
            }

            // Without the old sandbox's files, the code comes back from its backup.
            if (! $backup && ! $snapshot) {
                $this->restoreCode($project, $sandbox, $report);
            }

            $this->republish($project, $republishAs, $sandbox, $report);

            return $sandbox;
        } catch (Throwable $e) {
            if ($keepsOld) {
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
     * The snapshot a new sandbox gets its files from: a fresh one of the old sandbox when it's still there, else the
     * latest. Null when the new sandbox couldn't reach it (a local disk and a remote provider), or the old sandbox
     * can't be snapshotted (no snapshot tool and no way to copy it in); its files are then copied through the platform.
     */
    protected function snapshotToKeep(Project $project, bool $oldExists): ?ProjectSnapshot
    {
        if ($this->snapshots->transport(config('sandbox.provider')) === null) {
            return null;
        }

        if (! $oldExists) {
            return $this->snapshots->latest($project);
        }

        try {
            return $this->snapshots->take($project, 'update');
        } catch (SandboxException $e) {
            report($e);

            return null;
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
