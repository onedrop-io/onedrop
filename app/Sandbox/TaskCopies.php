<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use Illuminate\Http\File as LocalFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * A task's own copy of the app (TASK-003), whatever the app is built with: Main's sandbox is copied at one
 * instant (files, dependencies and any databases kept in it; see docker/sandbox/fork), the task works on a
 * git branch in the copy, and its work goes back to Main (or Main's to it) as a git merge. Whatever the stack
 * needs after a merge (installing dependencies, migrating) is left to the agent.
 */
class TaskCopies
{
    /** How long to wait for Main's sandbox to finish copying itself, in seconds. */
    public const SNAPSHOT_TIMEOUT = 600;

    /** How long to wait for one Docker image to be saved or loaded, in seconds. */
    public const IMAGE_TIMEOUT = 600;

    public function __construct(
        protected SandboxProvider $provider,
        protected SandboxUpdater $updater,
        protected ProjectSnapshots $snapshots,
        protected SandboxTools $tools,
        protected GitRemote $remote,
    ) {}

    /**
     * Make the task's copy of the app from Main's sandbox, on branch task-<id>. Returns the data services
     * the app's settings point at outside the sandbox, which the copy still shares with Main.
     *
     * @return list<string>
     *
     * @throws SandboxException
     */
    public function fork(Task $task): array
    {
        $project = $task->project;
        $main = $this->mainSandbox($project);
        $local = storage_path('framework/task-copy-'.uniqid());
        $copy = null;

        try {
            $this->snapshot($main, $local);

            CreateSandbox::dispatchSync($project, $task);
            $copy = $task->sandbox()->firstOrFail();

            if ($copy->status !== SandboxStatus::Running) {
                throw new SandboxException($copy->error ?: "The task's copy didn't start.");
            }

            foreach (SandboxUpdater::KEPT_PATHS as $index => $path) {
                $this->provider->copyIn($copy->external_id, "{$local}/{$index}", $path);
            }

            $this->provider->exec($copy->external_id, ['bash', '-c', SandboxUpdater::USE_IMAGE_SHELL_SETUP]);
            $this->carryImages($project, $main, $copy);
            // A pull request's task works on the pull request's own branch, at its newest commit (GIT-014).
            $branch = $task->isPullRequest()
                ? $this->checkOut($task, $copy)
                : $this->run($copy, ['/opt/onedrop/fork', 'branch', self::branch($task)]);
            // The app's dev server (.onedrop/dev) and its services came with the files; start them.
            $this->provider->exec($copy->external_id, ['/opt/onedrop/restart']);

            $task->update(['base_commit' => trim($branch) ?: null, 'applied_at' => null, ...($task->isPullRequest() ? ['pull_request_head_sha' => trim($branch) ?: null] : [])]);

            return $this->externalServices($copy);
        } catch (Throwable $e) {
            if ($copy?->external_id) {
                $this->destroy($copy->external_id);
            }

            $copy?->update(['status' => SandboxStatus::Failed, 'error' => $e->getMessage(), 'external_id' => null]);

            throw $e instanceof SandboxException ? $e : new SandboxException($e->getMessage(), previous: $e);
        } finally {
            File::deleteDirectory($local);
        }
    }

    /**
     * Merge the task's work into Main.
     *
     * @return list<string> files left with conflicts in Main (the merge waits there to be finished), or none
     *
     * @throws SandboxException
     */
    public function apply(Task $task): array
    {
        return $this->merge($this->copySandbox($task), $this->mainSandbox($task->project), "Task: {$task->title}", "Apply task: {$task->title}");
    }

    /**
     * Merge Main's newer work into the task's copy.
     *
     * @return list<string> files left with conflicts in the copy, or none
     *
     * @throws SandboxException
     */
    public function updateFromMain(Task $task): array
    {
        return $this->merge($this->mainSandbox($task->project), $this->copySandbox($task), 'Checkpoint', 'Update from Main');
    }

    /**
     * Commit what's changed in a pull request's task copy and push its branch to the pull request (GIT-014).
     * Returns the pushed commit.
     *
     * @throws SandboxException|GitException
     */
    public function pushPullRequest(Task $task): string
    {
        if ($task->pull_request_fork) {
            throw new GitException(__('This pull request comes from a fork, so OneDrop can\'t push to it.'));
        }

        $copy = $this->copySandbox($task);
        $dir = '/tmp/onedrop-bundle-'.Str::lower(Str::random(8));
        $local = storage_path('framework/task-bundle-'.uniqid());

        try {
            $this->run($copy, ['bash', '-c', 'mkdir -p "$1" && /opt/onedrop/fork bundle "$1/branch.bundle" "$2"', 'bundle', $dir, 'Checkpoint']);
            File::ensureDirectoryExists($local);
            $this->provider->copyOut($copy->external_id, $dir, $local);

            return $this->remote->pushBundle($task->project, "{$local}/branch.bundle", (string) $task->pull_request_branch);
        } finally {
            File::deleteDirectory($local);
            $this->clearUp($copy, $dir);
        }
    }

    /**
     * Merge commits pushed to a pull request since into its task's copy (GIT-014). Nothing is merged when the
     * pull request's newest commit is still the one the task last had.
     *
     * @return array{sha: string, merged: bool, conflicts: list<string>} the pull request's newest commit, and files left with conflicts in the copy
     *
     * @throws SandboxException|GitException
     */
    public function pullPullRequest(Task $task): array
    {
        $copy = $this->copySandbox($task);
        $local = storage_path('framework/task-bundle-'.uniqid());
        $dir = '/tmp/onedrop-bundle-'.Str::lower(Str::random(8));

        try {
            $sha = $this->remote->fetchPullRequest($task->project, (int) $task->pull_request_number, $local);

            if ($sha === $task->pull_request_head_sha) {
                return ['sha' => $sha, 'merged' => false, 'conflicts' => []];
            }

            $this->provider->copyIn($copy->external_id, $local, $dir);

            return ['sha' => $sha, 'merged' => true, 'conflicts' => $this->mergeIn($copy, "{$dir}/pull.bundle", "Merge #{$task->pull_request_number} from GitHub")];
        } finally {
            File::deleteDirectory($local);
            $this->clearUp($copy, $dir);
        }
    }

    /**
     * Data services (databases, caches, queues) the app's settings point at outside the sandbox.
     *
     * @return list<string>
     */
    public function externalServices(Sandbox $sandbox): array
    {
        try {
            $result = $this->provider->exec($sandbox->external_id, ['/opt/onedrop/fork', 'services']);
        } catch (SandboxException) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->output))));
    }

    public static function branch(Task $task): string
    {
        return "task-{$task->id}";
    }

    /**
     * Copy Main's kept paths, as they were at one instant, into a local directory (one numbered folder per path).
     *
     * @throws SandboxException
     */
    protected function snapshot(Sandbox $main, string $local): void
    {
        $dir = '/tmp/onedrop-fork-'.Str::lower(Str::random(8));

        // As root, like an update's copy: a database in one of the app's containers keeps its files as its own user.
        $this->provider->exec($main->external_id, ['/opt/onedrop/fork', 'snapshot', $dir, ...SandboxUpdater::KEPT_PATHS], detach: true, root: true);

        try {
            $this->waitForSnapshot($main, $dir);

            foreach (SandboxUpdater::KEPT_PATHS as $index => $path) {
                File::ensureDirectoryExists("{$local}/{$index}");
                $this->provider->copyOut($main->external_id, "{$dir}/{$index}", "{$local}/{$index}");
            }
        } finally {
            $this->provider->exec($main->external_id, ['rm', '-rf', $dir], root: true);
        }
    }

    /**
     * @throws SandboxException
     */
    protected function waitForSnapshot(Sandbox $main, string $dir): void
    {
        $this->waitFor($main, $dir, self::SNAPSHOT_TIMEOUT, "Couldn't copy the app", 'Copying the app took too long.');
    }

    /**
     * Wait for a detached fork command to mark $dir done (or failed, with what it said).
     *
     * @throws SandboxException
     */
    protected function waitFor(Sandbox $sandbox, string $dir, int $seconds, string $failure, string $tooLong): void
    {
        $deadline = now()->addSeconds($seconds);

        do {
            $check = $this->provider->exec($sandbox->external_id, ['bash', '-c', 'if [ -f "$1/.done" ]; then echo done; elif [ -f "$1/.failed" ]; then cat "$1/.failed"; exit 3; fi', 'check', $dir]);

            if ($check->exitCode === 3) {
                // One line: a copy that fails on every file says so for each of them.
                throw new SandboxException("{$failure}: ".(strtok(trim($check->output), "\n") ?: 'unknown error'));
            }

            if (trim($check->output) === 'done') {
                return;
            }

            sleep(1);
        } while (now()->lessThan($deadline));

        throw new SandboxException($tooLong);
    }

    /**
     * Give the copy the Docker images Main built itself, which no registry has: without them, its compose stack builds
     * them all again (minutes), or can't. Each is kept once on the snapshot disk by image id, so later tasks only load
     * it; the sandboxes upload and download it themselves through signed links when the disk is S3-compatible. Best
     * effort: a copy without them builds them, as before.
     */
    protected function carryImages(Project $project, Sandbox $main, Sandbox $copy): void
    {
        $transport = $this->snapshots->transport($main->provider);

        if ($transport === null || $transport !== $this->snapshots->transport($copy->provider)) {
            return;
        }

        try {
            $images = $this->builtImages($main);

            if ($images === [] || ($this->tools->available() && ! $this->tools->ensure($this->provider, $copy->external_id, ['fork']))) {
                return;
            }

            $disk = $this->snapshots->disk();
            $folder = $this->snapshots->path($project->id).'/images';
            $paths = [];

            foreach ($images as $id => $tags) {
                $path = $paths[] = "{$folder}/".Str::after($id, 'sha256:').'.tar.zst';

                if (! $disk->exists($path)) {
                    $this->saveImage($main, $transport, $tags, $path);
                }

                $this->loadImage($copy, $transport, $path);
            }

            // Ones Main no longer has (it built them again since) aren't needed.
            $disk->delete(array_values(array_diff($disk->files($folder), $paths)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The images Main's Docker built itself: id => tags.
     *
     * @return array<string, list<string>>
     */
    protected function builtImages(Sandbox $main): array
    {
        $images = [];

        foreach (preg_split('/\R/', trim($this->run($main, ['/opt/onedrop/fork', 'images']))) ?: [] as $line) {
            $words = preg_split('/\s+/', trim($line)) ?: [];

            if (count($words) > 1 && str_starts_with($words[0], 'sha256:')) {
                $images[$words[0]] = array_slice($words, 1);
            }
        }

        return $images;
    }

    /**
     * @param  list<string>  $tags
     *
     * @throws SandboxException
     */
    protected function saveImage(Sandbox $main, string $transport, array $tags, string $path): void
    {
        $dir = '/tmp/onedrop-image-'.Str::lower(Str::random(8));
        $env = [];

        if ($transport === 'links') {
            ['url' => $url, 'headers' => $headers] = $this->snapshots->disk()->temporaryUploadUrl($path, now()->addMinutes(30));
            $env = [
                'ONEDROP_IMAGE_URL' => $url,
                'ONEDROP_IMAGE_HEADERS' => $this->snapshots->headerLines($headers),
            ];
        }

        $local = storage_path('framework/task-image-'.uniqid());

        try {
            $this->provider->exec($main->external_id, ['/opt/onedrop/fork', 'save-image', $dir, "{$dir}/image.tar.zst", ...$tags], $env, detach: true);
            $this->waitFor($main, $dir, self::IMAGE_TIMEOUT, "Couldn't save the app's {$tags[0]} image", "Saving the app's {$tags[0]} image took too long.");

            if ($transport === 'copy') {
                File::ensureDirectoryExists($local);
                $this->provider->copyOut($main->external_id, $dir, $local);
                $this->snapshots->disk()->putFileAs(dirname($path), new LocalFile("{$local}/image.tar.zst"), basename($path));
            }
        } finally {
            File::deleteDirectory($local);
            $this->provider->exec($main->external_id, ['rm', '-rf', $dir], root: true);
        }
    }

    /**
     * @throws SandboxException
     */
    protected function loadImage(Sandbox $copy, string $transport, string $path): void
    {
        $dir = '/tmp/onedrop-image-'.Str::lower(Str::random(8));
        $local = storage_path('framework/task-image-'.uniqid());

        try {
            if ($transport === 'links') {
                $this->provider->exec($copy->external_id, ['/opt/onedrop/fork', 'load-image', $dir, 'url'], [
                    'ONEDROP_IMAGE_URL' => $this->snapshots->disk()->temporaryUrl($path, now()->addMinutes(30)),
                ], detach: true);
            } else {
                File::ensureDirectoryExists($local);
                $this->download($path, "{$local}/image.tar.zst");
                $this->provider->copyIn($copy->external_id, $local, $dir);
                $this->provider->exec($copy->external_id, ['/opt/onedrop/fork', 'load-image', $dir, "{$dir}/image.tar.zst"], detach: true);
            }

            $this->waitFor($copy, $dir, self::IMAGE_TIMEOUT, "Couldn't load the app's image", "Loading the app's image took too long.");
        } finally {
            File::deleteDirectory($local);
            $this->provider->exec($copy->external_id, ['rm', '-rf', $dir], root: true);
        }
    }

    /**
     * Commit what's in $from, carry its branch to $to as a git bundle, and merge it there.
     *
     * @return list<string> conflicted files
     *
     * @throws SandboxException
     */
    protected function merge(Sandbox $from, Sandbox $to, string $commitMessage, string $mergeMessage): array
    {
        $dir = '/tmp/onedrop-bundle-'.Str::lower(Str::random(8));
        $local = storage_path('framework/task-bundle-'.uniqid());

        try {
            $this->run($from, ['bash', '-c', 'mkdir -p "$1" && /opt/onedrop/fork bundle "$1/branch.bundle" "$2"', 'bundle', $dir, $commitMessage]);

            File::ensureDirectoryExists($local);
            $this->provider->copyOut($from->external_id, $dir, $local);
            $this->provider->copyIn($to->external_id, $local, $dir);

            return $this->mergeIn($to, "{$dir}/branch.bundle", $mergeMessage);
        } finally {
            File::deleteDirectory($local);

            foreach ([$from, $to] as $sandbox) {
                $this->clearUp($sandbox, $dir);
            }
        }
    }

    /**
     * Merge a bundle's HEAD into the sandbox's current branch.
     *
     * @return list<string> files left with conflicts, or none
     *
     * @throws SandboxException
     */
    protected function mergeIn(Sandbox $to, string $bundle, string $mergeMessage): array
    {
        $result = $this->provider->exec($to->external_id, ['/opt/onedrop/fork', 'merge', $bundle, $mergeMessage]);

        if ($result->exitCode === 3) {
            return array_values(array_filter(array_map('trim', explode("\n", $result->output))));
        }

        if (! $result->successful()) {
            throw new SandboxException("Couldn't merge: ".(strtok(trim($result->errorOutput), "\n") ?: 'git failed'));
        }

        return [];
    }

    protected function clearUp(Sandbox $sandbox, string $dir): void
    {
        try {
            $this->provider->exec($sandbox->external_id, ['rm', '-rf', $dir]);
        } catch (SandboxException) {
            // A leftover in /tmp does no harm.
        }
    }

    /**
     * Switch a new copy to the pull request's branch at its newest commit, fetched on the platform. Returns that commit.
     *
     * @throws SandboxException|GitException
     */
    protected function checkOut(Task $task, Sandbox $copy): string
    {
        $local = storage_path('framework/task-bundle-'.uniqid());
        $dir = '/tmp/onedrop-bundle-'.Str::lower(Str::random(8));

        try {
            $this->remote->fetchPullRequest($task->project, (int) $task->pull_request_number, $local);
            $this->provider->copyIn($copy->external_id, $local, $dir);

            return $this->run($copy, ['/opt/onedrop/fork', 'checkout', "{$dir}/pull.bundle", (string) $task->pull_request_branch]);
        } finally {
            File::deleteDirectory($local);
            $this->clearUp($copy, $dir);
        }
    }

    /**
     * Main's sandbox, running and with the current tool files (so it has the fork tool).
     *
     * @throws SandboxException
     */
    protected function mainSandbox(Project $project): Sandbox
    {
        if (! $project->mainSandboxBusy()) {
            $this->updater->updateIfOutdated($project, rebuild: false);
        }

        $main = $project->sandbox()->first();

        if ($main?->status !== SandboxStatus::Running || $main->external_id === null) {
            throw new SandboxException("The app's main sandbox isn't running.");
        }

        return $main;
    }

    /**
     * @throws SandboxException
     */
    protected function copySandbox(Task $task): Sandbox
    {
        $copy = $task->sandbox()->first();

        if ($copy?->status !== SandboxStatus::Running || $copy->external_id === null) {
            throw new SandboxException("The task's copy of the app isn't running.");
        }

        return $copy;
    }

    /**
     * @throws SandboxException
     */
    protected function download(string $path, string $file): void
    {
        $source = $this->snapshots->disk()->readStream($path) ?? throw new SandboxException("Couldn't read {$path}.");
        $target = fopen($file, 'wb') ?: throw new SandboxException("Couldn't write {$file}.");

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($target);
            fclose($source);
        }
    }

    /**
     * Run a command that must succeed, returning its output.
     *
     * @param  list<string>  $command
     *
     * @throws SandboxException
     */
    protected function run(Sandbox $sandbox, array $command): string
    {
        $result = $this->provider->exec($sandbox->external_id, $command);

        if (! $result->successful()) {
            throw new SandboxException(strtok(trim($result->errorOutput), "\n") ?: 'Command failed: '.implode(' ', $command));
        }

        return $result->output;
    }

    protected function destroy(string $externalId): void
    {
        try {
            $this->provider->destroy($externalId);
        } catch (SandboxException $e) {
            report($e);
        }
    }
}
