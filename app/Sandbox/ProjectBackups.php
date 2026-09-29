<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File as LocalFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps each project's git history outside its sandbox: a verified git bundle of every branch on the backup disk
 * (SANDBOX_BACKUP_DISK), refreshed after agent turns (BackupProject) and cloned into sandboxes that start without
 * the project's files (SandboxUpdater), so the code survives a lost sandbox or a provider switch.
 */
class ProjectBackups
{
    /** Where bundles are made, and restored from, inside a sandbox. */
    protected const SANDBOX_DIRECTORY = '/tmp/zap-backup';

    /** Exit code of BUNDLE when HEAD is already backed up (or there are no commits yet). */
    protected const NOTHING_NEW = 3;

    /** Lets git run on the workspace whichever user the provider runs commands as. */
    protected const ANY_OWNER = 'export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="*"; ';

    /**
     * Bundles every branch and tag unless HEAD is $ZAP_BACKED_UP, then prints HEAD.
     */
    protected const BUNDLE = self::ANY_OWNER.'set -e; cd /workspace; '
        .'head="$(git rev-parse --verify -q HEAD)" || exit 3; [ "$head" != "${ZAP_BACKED_UP:-}" ] || exit 3; '
        .'rm -rf /tmp/zap-backup; mkdir -p /tmp/zap-backup; '
        .'git bundle create -q /tmp/zap-backup/repo.bundle --all; git bundle verify -q /tmp/zap-backup/repo.bundle >/dev/null 2>&1; '
        .'echo "$head"';

    /**
     * Clones the bundle with all its branches into /workspace (over whatever a fresh image put there), without a
     * remote pointing at the bundle.
     */
    protected const RESTORE = self::ANY_OWNER.'set -e; cd /tmp/zap-backup; '
        .'git clone -q repo.bundle repo; git -C repo remote remove origin; '
        .'git -C repo fetch -q --update-head-ok "$PWD/repo.bundle" "+refs/heads/*:refs/heads/*" "+refs/tags/*:refs/tags/*"; '
        .'cp -a repo/. /workspace/; cd /; rm -rf /tmp/zap-backup';

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Copy the project's git history out of its running sandbox, unless its latest commit is already backed up
     * ($force copies it anyway, e.g. so a push has every branch). Returns whether a new backup was stored.
     *
     * @throws SandboxException
     */
    public function backUp(Project $project, bool $force = false): bool
    {
        $sandbox = $project->sandbox()->first();

        if (! $this->isRunning($sandbox)) {
            return false;
        }

        $result = $this->provider->exec($sandbox->external_id, ['bash', '-c', self::BUNDLE], ['ZAP_BACKED_UP' => $force ? '' : (string) $project->backup_commit]);

        if ($result->exitCode === self::NOTHING_NEW) {
            return false;
        }

        if (! $result->successful()) {
            throw new SandboxException('Could not bundle the project\'s git history: '.trim($result->errorOutput ?: $result->output));
        }

        $directory = $this->localDirectory();

        try {
            $this->provider->copyOut($sandbox->external_id, self::SANDBOX_DIRECTORY, $directory);

            if (! File::exists("{$directory}/repo.bundle")) {
                throw new SandboxException('The project\'s git bundle did not copy out of its sandbox.');
            }

            $this->disk()->putFileAs($this->path($project), new LocalFile("{$directory}/repo.bundle"), 'repo.bundle');
        } finally {
            File::deleteDirectory($directory);
        }

        $project->update(['backup_commit' => trim($result->output), 'backed_up_at' => now()]);

        return true;
    }

    /**
     * Whether the project has code backed up.
     */
    public function exists(Project $project): bool
    {
        return $this->disk()->exists($this->bundlePath($project));
    }

    /**
     * Put the backed-up code into a sandbox that started without the project's files, and start the app.
     * Returns whether there was a backup to restore.
     *
     * @throws SandboxException
     */
    public function restore(Project $project, Sandbox $sandbox): bool
    {
        if (! $this->isRunning($sandbox) || ! $this->exists($project)) {
            return false;
        }

        $directory = $this->download($project);

        try {
            $this->provider->copyIn($sandbox->external_id, $directory, self::SANDBOX_DIRECTORY);
        } finally {
            File::deleteDirectory($directory);
        }

        $result = $this->provider->exec($sandbox->external_id, ['bash', '-c', self::RESTORE]);

        if (! $result->successful()) {
            throw new SandboxException('Could not restore the project\'s code from its backup: '.trim($result->errorOutput ?: $result->output));
        }

        // The app's dev server (.zap/dev) came back with the code; start it.
        $this->provider->exec($sandbox->external_id, ['/opt/zap/restart']);

        return true;
    }

    /**
     * Copy the project's backup into a new local directory (as repo.bundle) and return the directory.
     * The caller deletes it.
     *
     * @throws SandboxException when there's no backup or it can't be read
     */
    public function download(Project $project): string
    {
        if (! $this->exists($project)) {
            throw new SandboxException(__('The project has no commits to push yet.'));
        }

        $backup = $this->disk()->readStream($this->bundlePath($project));

        if ($backup === null) {
            throw new SandboxException(__('Couldn\'t read the project\'s backup. Try again.'));
        }

        $directory = $this->localDirectory();
        Storage::build(['driver' => 'local', 'root' => $directory])->writeStream('repo.bundle', $backup);

        return $directory;
    }

    /**
     * Remove the project's backup (the project is being deleted).
     */
    public function delete(Project $project): void
    {
        $this->disk()->deleteDirectory($this->path($project));
    }

    protected function isRunning(?Sandbox $sandbox): bool
    {
        return $sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null;
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('sandbox.backup_disk'));
    }

    protected function path(Project $project): string
    {
        return "project-backups/{$project->id}";
    }

    protected function bundlePath(Project $project): string
    {
        return $this->path($project).'/repo.bundle';
    }

    protected function localDirectory(): string
    {
        $directory = storage_path('framework/project-backup-'.uniqid());
        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
