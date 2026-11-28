<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\OpenTaskPullRequest;
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

    /** How long Main may take to upload its files to storage, or the copy to download and unpack them, in seconds. */
    public const TRANSFER_TIMEOUT = 900;

    /** How long to wait for one Docker image to be saved or loaded, in seconds. */
    public const IMAGE_TIMEOUT = 600;

    /** @var array<string, list<string>> the fork tool command to run in each sandbox, by its id */
    protected array $forkTools = [];

    public function __construct(
        protected SandboxProvider $provider,
        protected SandboxUpdater $updater,
        protected ProjectSnapshots $snapshots,
        protected SandboxTools $tools,
        protected GitRemote $remote,
        protected WorkspaceGit $git,
    ) {}

    /**
     * Make the task's copy of the app from Main's sandbox, or from $from's copy (TASK-005), on branch task-<id>. Returns
     * the data services the app's settings point at outside the sandbox, which the copy still shares with Main.
     *
     * @return list<string>
     *
     * @throws SandboxException
     */
    public function fork(Task $task, ?Task $from = null): array
    {
        $project = $task->project;
        // Copied from the other task's copy, its files, uncommitted changes and data as they are now.
        $main = $from !== null ? $this->sourceCopy($from) : $this->mainSandbox($project);
        $local = storage_path('framework/task-copy-'.uniqid());
        $copy = null;
        // With signed links, the files go from Main to storage to the copy, never through the platform: a large app
        // (gigabytes) crashed Laravel Cloud's queue worker on its way through.
        $links = $this->snapshots->transport($main->provider) === 'links' && $this->snapshots->transport($project->sandboxProvider()) === 'links';
        $stored = [];

        try {
            if ($links) {
                $stored = $this->uploadSnapshot($task, $main);
            } else {
                $this->snapshot($main, $local);
            }

            CreateSandbox::dispatchSync($project, $task);
            $copy = $task->sandbox()->firstOrFail();

            if ($copy->status !== SandboxStatus::Running) {
                throw new SandboxException($copy->error ?: "The task's copy didn't start.");
            }

            if ($links) {
                $this->downloadSnapshot($copy, $stored);
            } else {
                foreach (SandboxUpdater::KEPT_PATHS as $index => $path) {
                    $this->provider->copyIn($copy->external_id, "{$local}/{$index}", $path);
                }
            }

            $this->provider->exec($copy->external_id, ['bash', '-c', SandboxUpdater::USE_IMAGE_SHELL_SETUP]);
            $this->carryImages($project, $main, $copy);
            // A pull request's task works on the pull request's own branch, at its newest commit (GIT-014).
            $branch = $task->isPullRequest()
                ? $this->checkOut($task, $copy)
                : $this->run($copy, ['/opt/onedrop/fork', 'branch', self::branch($task)]);
            // The app's dev server (.onedrop/dev) and its services came with the files; start them.
            $this->provider->exec($copy->external_id, ['/opt/onedrop/restart']);

            $task->update([
                'base_commit' => trim($branch) ?: null,
                'applied_at' => null,
                'branch' => $task->isPullRequest() ? $task->pull_request_branch : self::branch($task),
                ...($task->isPullRequest() ? ['pull_request_head_sha' => trim($branch) ?: null] : []),
            ]);

            return $this->externalServices($copy);
        } catch (Throwable $e) {
            if ($copy?->external_id) {
                $this->destroy($copy->external_id);
            }

            $copy?->update(['status' => SandboxStatus::Failed, 'error' => $e->getMessage(), 'external_id' => null]);

            throw $e instanceof SandboxException ? $e : new SandboxException($e->getMessage(), previous: $e);
        } finally {
            File::deleteDirectory($local);

            if ($stored !== []) {
                $this->snapshots->disk()->delete(array_values($stored));
            }
        }
    }

    /**
     * A pull request's task's own sandbox, fresh: the pull request's branch checked out into a new, empty sandbox, then
     * its app started (.onedrop/dev sets it up, as after an import). Nothing comes from Main, so it's quick whatever
     * Main holds, and its databases start as the app makes them (GIT-014). Returns the data services the app's settings
     * point at outside the sandbox.
     *
     * @return list<string>
     *
     * @throws SandboxException
     */
    public function checkOutFresh(Task $task): array
    {
        $project = $task->project;
        $copy = null;

        try {
            CreateSandbox::dispatchSync($project, $task);
            $copy = $task->sandbox()->firstOrFail();

            if ($copy->status !== SandboxStatus::Running) {
                throw new SandboxException($copy->error ?: "The task's copy didn't start.");
            }

            // The sandbox fetches it itself from a GitHub App repository; otherwise the platform fetches just the pull
            // request's commits and hands them over.
            $sha = $this->remote->fetchesInSandbox($project)
                ? $this->remote->checkOutPullRequestInSandbox($project, $copy, (int) $task->pull_request_number, (string) $task->pull_request_branch, (string) $task->pull_request_base)
                : trim($this->checkOut($task, $copy));

            // The remote as `origin`, now that there's a repository (GIT-021).
            app(GitOrigin::class)->syncQuietly($copy);
            // The app's dev server (.onedrop/dev) sets it up and starts it, in the background.
            $this->provider->exec($copy->external_id, ['/opt/onedrop/restart']);

            $task->update(['base_commit' => $sha ?: null, 'applied_at' => null, 'pull_request_head_sha' => $sha ?: null]);

            return $this->externalServices($copy);
        } catch (Throwable $e) {
            if ($copy?->external_id) {
                $this->destroy($copy->external_id);
            }

            $copy?->update(['status' => SandboxStatus::Failed, 'error' => $e->getMessage(), 'external_id' => null]);

            throw $e instanceof SandboxException ? $e : new SandboxException($e->getMessage(), previous: $e);
        }
    }

    /**
     * A task's own sandbox, fresh from a branch of the project's remote (TASK-005): the task's chosen branch (or the
     * remote's default one) checked out into a new, empty sandbox, then its app started (.onedrop/dev sets it up, as
     * after an import). Nothing comes from Main. The copy works on that branch, or on a branch of its own off it
     * (workBranch). Returns the data services the app's settings point at outside the sandbox.
     *
     * @return list<string>
     *
     * @throws SandboxException
     */
    public function checkOutBranch(Task $task): array
    {
        $project = $task->project;
        $copy = null;

        try {
            $heads = $this->remote->heads($project);
            $from = $task->start_branch ?? $heads['default'] ?? throw new GitException(__('The repository has no branches yet. Start the task from Main instead.'));

            if (! in_array($from, $heads['branches'], true)) {
                throw new GitException(__('The repository has no branch called :branch any more.', ['branch' => $from]));
            }

            $branch = self::workBranch($task, $from, $heads['default']);

            CreateSandbox::dispatchSync($project, $task);
            $copy = $task->sandbox()->firstOrFail();

            if ($copy->status !== SandboxStatus::Running) {
                throw new SandboxException($copy->error ?: "The task's copy didn't start.");
            }

            // The sandbox fetches it itself from a GitHub App repository; otherwise the platform fetches it and hands it
            // over, as an import (the branch under its own name), then the copy's branch takes the task's name.
            if ($this->remote->fetchesInSandbox($project)) {
                $sha = $this->remote->checkOutBranchInSandbox($project, $copy, $from, $branch);
            } else {
                $this->remote->pull($project, $from, $copy);
                $sha = ($branch === $from ? $this->git->status($copy) : $this->git->renameBranch($copy, $branch))['head'];
            }

            // The remote as `origin`, now that there's a repository (GIT-021).
            app(GitOrigin::class)->syncQuietly($copy);
            // The app's dev server (.onedrop/dev) sets it up and starts it, in the background.
            $this->provider->exec($copy->external_id, ['/opt/onedrop/restart']);

            $task->update(['base_commit' => $sha ?: null, 'applied_at' => null, 'start_branch' => $from, 'branch' => $branch]);

            return $this->externalServices($copy);
        } catch (Throwable $e) {
            if ($copy?->external_id) {
                $this->destroy($copy->external_id);
            }

            $copy?->update(['status' => SandboxStatus::Failed, 'error' => $e->getMessage(), 'external_id' => null]);

            throw $e instanceof SandboxException ? $e : new SandboxException($e->getMessage(), previous: $e);
        }
    }

    /**
     * The branch a task started from $from works on: $from itself, unless it's the repository's default branch (a
     * task never pushes there) or another open task already works on it (two copies pushing to one branch race), in
     * which case a branch of its own off it, named as Open pull request names it (GIT-024).
     */
    public static function workBranch(Task $task, string $from, ?string $default): string
    {
        $ownBranch = in_array($from, ['main', 'master'], true) || $from === $default || Task::usingBranch($task->project, $from, $task->id) !== null;

        return $ownBranch ? OpenTaskPullRequest::branch($task) : $from;
    }

    /**
     * The running copy of the task another task starts from (TASK-005).
     *
     * @throws SandboxException
     */
    protected function sourceCopy(Task $from): Sandbox
    {
        $copy = $from->sandbox()->first();

        if ($copy?->status !== SandboxStatus::Running || $copy->external_id === null) {
            throw new SandboxException(__('":title" doesn\'t have a running copy of the app to start from.', ['title' => $from->title]));
        }

        $this->awake($copy);

        return $copy;
    }

    /**
     * Main's kept paths at one instant (fork snapshot, as root), uploaded by Main itself to the snapshot disk through
     * signed links. Returns where each path's archive is, by index.
     *
     * @return array<int, string>
     *
     * @throws SandboxException
     */
    protected function uploadSnapshot(Task $task, Sandbox $main): array
    {
        $dir = '/tmp/onedrop-fork-'.Str::lower(Str::random(8));
        $status = "{$dir}-put";
        $folder = $this->snapshots->path($task->project_id).'/tasks/'.$task->id.'/copy-'.Str::lower(Str::random(8));
        $stored = [];
        $env = [];

        foreach (array_keys(SandboxUpdater::KEPT_PATHS) as $index) {
            $stored[$index] = "{$folder}/{$index}.tar.zst";
            ['url' => $url, 'headers' => $headers] = $this->snapshots->disk()->temporaryUploadUrl($stored[$index], now()->addSeconds(self::SNAPSHOT_TIMEOUT + self::TRANSFER_TIMEOUT));
            $env["ONEDROP_FORK_URL_{$index}"] = $url;
            $env["ONEDROP_FORK_HEADERS_{$index}"] = $this->snapshots->headerLines($headers);
        }

        $this->provider->exec($main->external_id, ['/opt/onedrop/fork', 'snapshot', $dir, ...SandboxUpdater::KEPT_PATHS], detach: true, root: true);

        try {
            $this->waitForSnapshot($main, $dir);
            $this->provider->exec($main->external_id, [...$this->forkTool($main), 'put', $status, $dir], $env, detach: true, root: true);
            $this->waitFor($main, $status, self::TRANSFER_TIMEOUT, "Couldn't store the copy of the app", 'Storing the copy of the app took too long.');
        } finally {
            $this->provider->exec($main->external_id, ['rm', '-rf', $dir, $status], root: true);
        }

        return $stored;
    }

    /**
     * The copy downloads and unpacks Main's paths from the snapshot disk itself, as root, keeping their owners.
     *
     * @param  array<int, string>  $stored
     *
     * @throws SandboxException
     */
    protected function downloadSnapshot(Sandbox $copy, array $stored): void
    {
        $status = '/tmp/onedrop-fork-get-'.Str::lower(Str::random(8));
        $env = [];

        foreach ($stored as $index => $path) {
            $env["ONEDROP_FORK_URL_{$index}"] = $this->snapshots->disk()->temporaryUrl($path, now()->addSeconds(self::TRANSFER_TIMEOUT));
        }

        $this->provider->exec($copy->external_id, [...$this->forkTool($copy), 'get', $status, ...SandboxUpdater::KEPT_PATHS], $env, detach: true, root: true);

        try {
            $this->waitFor($copy, $status, self::TRANSFER_TIMEOUT, "Couldn't unpack the copy of the app", 'Unpacking the copy of the app took too long.');
        } finally {
            $this->provider->exec($copy->external_id, ['rm', '-rf', $status], root: true);
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
        $copy = $this->copySandbox($task);
        $conflicts = $this->merge($this->mainSandbox($task->project), $copy, 'Checkpoint', 'Update from Main');

        // The task's changes are now shown against Main as it brought it in, not Main's work as the task's (GIT-030).
        $incoming = $this->provider->exec($copy->external_id, ['/opt/onedrop/fork', 'incoming']);

        if ($incoming->successful() && preg_match('/^[0-9a-f]{40}$/', trim($incoming->output))) {
            $task->update(['base_commit' => trim($incoming->output)]);
        }

        return $conflicts;
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

        return $this->pushBranch($task, (string) $task->pull_request_branch, 'Checkpoint');
    }

    /**
     * Commit what's changed in the task's copy (as $message) and push its branch to $branch on the project's remote,
     * never forcing. Returns the pushed commit. Opening a pull request from a task pushes this way too (GIT-024).
     *
     * @throws SandboxException|GitException
     */
    public function pushBranch(Task $task, string $branch, string $message): string
    {
        $copy = $this->copySandbox($task);
        $dir = '/tmp/onedrop-bundle-'.Str::lower(Str::random(8));
        $local = storage_path('framework/task-bundle-'.uniqid());

        try {
            $this->run($copy, ['bash', '-c', 'mkdir -p "$1" && /opt/onedrop/fork bundle "$1/branch.bundle" "$2"', 'bundle', $dir, $message]);
            File::ensureDirectoryExists($local);
            $this->provider->copyOut($copy->external_id, $dir, $local);

            return $this->remote->pushBundle($task->project, "{$local}/branch.bundle", $branch);
        } finally {
            File::deleteDirectory($local);
            $this->clearUp($copy, $dir);
        }
    }

    /**
     * The branch on the remote a task's copy pushes to and pulls from without a pull request (TASK-003): its pull
     * request's branch once it has one, the copy's own branch when the user picked one, or, for the branch every copy
     * starts on (`task-<id>`), the readable name Open pull request uses (GIT-024).
     *
     * @throws SandboxException|GitException
     */
    public function remoteBranch(Task $task): string
    {
        if ($task->pull_request_branch !== null) {
            return $task->pull_request_branch;
        }

        $current = $this->git->status($this->copySandbox($task))['branch'];

        return match ($current) {
            null => throw new GitException(__('Switch the task\'s copy to a branch first.')),
            self::branch($task) => OpenTaskPullRequest::branch($task),
            default => $current,
        };
    }

    /**
     * Push the commits on a task's copy's branch to its branch on the remote (remoteBranch), never forcing and never to
     * the repository's default branch. Uncommitted changes stay behind, as with Main's Push. The copy's own `task-<id>`
     * branch takes the remote branch's name first, so the copy tracks it. Returns the branch and the pushed commit.
     *
     * @return array{branch: string, sha: string}
     *
     * @throws SandboxException|GitException
     */
    public function pushTaskBranch(Task $task): array
    {
        $copy = $this->copySandbox($task);
        $status = $this->git->status($copy);

        if ($status['head'] === null) {
            throw new GitException(__('Commit something first.'));
        }

        $branch = $this->remoteBranch($task);

        if (in_array($branch, ['main', 'master'], true) || $branch === $this->remote->remoteDefaultBranch($task->project)) {
            throw new GitException(__('A task doesn\'t push to :branch, the repository\'s default branch. Switch its copy to another branch, or open a pull request.', ['branch' => $branch]));
        }

        if ($status['branch'] !== $branch) {
            $this->git->renameBranch($copy, $branch);
            $task->update(['branch' => $branch]);
        }

        $dir = '/tmp/onedrop-bundle-'.Str::lower(Str::random(8));
        $local = storage_path('framework/task-bundle-'.uniqid());

        try {
            $this->run($copy, ['bash', '-c', 'mkdir -p "$1" && cd "${ONEDROP_WORKSPACE:-/workspace}" && git -c safe.directory="*" bundle create -q "$1/branch.bundle" HEAD', 'bundle', $dir]);
            File::ensureDirectoryExists($local);
            $this->provider->copyOut($copy->external_id, $dir, $local);
            $sha = $this->remote->pushBundle($task->project, "{$local}/branch.bundle", $branch);
        } finally {
            File::deleteDirectory($local);
            $this->clearUp($copy, $dir);
        }

        $this->git->pushed($copy, $branch, $sha);

        return ['branch' => $branch, 'sha' => $sha];
    }

    /**
     * Fast-forward a task's copy to its branch on the remote (remoteBranch), like Main's Pull. Returns the branch.
     *
     * @throws SandboxException|GitException
     */
    public function pullTaskBranch(Task $task): string
    {
        $branch = $this->remoteBranch($task);
        $this->remote->pull($task->project, $branch, $this->copySandbox($task));

        return $branch;
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
     * The command for a fork tool that knows the current subcommands (put, get, save-image, load-image), which a sandbox
     * made from an older image lacks: run there, an unknown one exits at once without marking its status, and the wait
     * for it runs out ("Storing the copy of the app took too long"). The sandbox's own is brought up to date when the
     * provider can write there; otherwise the current script goes to /tmp and runs from there.
     *
     * @return list<string>
     *
     * @throws SandboxException
     */
    protected function forkTool(Sandbox $sandbox): array
    {
        $id = (string) $sandbox->external_id;

        if (isset($this->forkTools[$id])) {
            return $this->forkTools[$id];
        }

        if (! $this->tools->available() || $this->tools->ensure($this->provider, $id, ['fork'])) {
            return $this->forkTools[$id] = ['/opt/onedrop/fork'];
        }

        $local = storage_path('framework/fork-tool-'.uniqid());
        $dir = '/tmp/onedrop-tools-'.Str::lower(Str::random(8));

        try {
            File::ensureDirectoryExists($local);
            File::copy($this->tools->files()['fork'], "{$local}/fork");
            $this->provider->copyIn($id, $local, $dir);
        } finally {
            File::deleteDirectory($local);
        }

        return $this->forkTools[$id] = ['bash', "{$dir}/fork"];
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

            if ($images === []) {
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
            $this->provider->exec($main->external_id, [...$this->forkTool($main), 'save-image', $dir, "{$dir}/image.tar.zst", ...$tags], $env, detach: true);
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
                $this->provider->exec($copy->external_id, [...$this->forkTool($copy), 'load-image', $dir, 'url'], [
                    'ONEDROP_IMAGE_URL' => $this->snapshots->disk()->temporaryUrl($path, now()->addMinutes(30)),
                ], detach: true);
            } else {
                File::ensureDirectoryExists($local);
                $this->download($path, "{$local}/image.tar.zst");
                $this->provider->copyIn($copy->external_id, $local, $dir);
                $this->provider->exec($copy->external_id, [...$this->forkTool($copy), 'load-image', $dir, "{$dir}/image.tar.zst"], detach: true);
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
            $this->run($from, ['bash', '-c', 'mkdir -p "$1" && /opt/onedrop/fork bundle "$1/branch.bundle" "$2"', 'bundle', $dir, $commitMessage], $this->forkEnv($from));

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
        $result = $this->provider->exec($to->external_id, ['/opt/onedrop/fork', 'merge', $bundle, $mergeMessage], $this->forkEnv($to));

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
     * Main's sandbox, awake, running and with the current tool files (so it has the fork tool).
     *
     * @throws SandboxException
     */
    protected function mainSandbox(Project $project): Sandbox
    {
        // Only the page showing a sandbox wakes it, and a task's page shows its copy: Main may be asleep (SBX-007).
        $this->awake($project->sandbox()->first());

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
    /**
     * Run a command in the task's running copy, waking it first.
     *
     * @param  list<string>  $command
     *
     * @throws SandboxException
     */
    public function execInCopy(Task $task, array $command): ExecResult
    {
        return $this->provider->exec($this->copySandbox($task)->external_id, $command);
    }

    protected function copySandbox(Task $task): Sandbox
    {
        $copy = $task->sandbox()->first();

        if ($copy?->status !== SandboxStatus::Running || $copy->external_id === null) {
            throw new SandboxException("The task's copy of the app isn't running.");
        }

        $this->awake($copy);

        return $copy;
    }

    /**
     * Wake a sandbox suspended or stopped for sitting idle (SBX-007) before running commands in it: Docker can't exec
     * in a stopped container. Waking can't fail here; the command that follows says what's wrong.
     */
    protected function awake(?Sandbox $sandbox): void
    {
        if ($sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null) {
            $sandbox->wake($this->provider);
        }
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
    /**
     * Main leaves its changes uncommitted when the project doesn't commit each turn (SCM-003): then the fork tool
     * carries its files without committing them, and applies a task's work as uncommitted changes.
     *
     * @return array<string, string>
     */
    protected function forkEnv(Sandbox $sandbox): array
    {
        return $sandbox->task_id === null && $sandbox->project?->commit_turns === false ? ['ONEDROP_COMMIT_TURNS' => '0'] : [];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function run(Sandbox $sandbox, array $command, array $env = []): string
    {
        $result = $this->provider->exec($sandbox->external_id, $command, $env);

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
