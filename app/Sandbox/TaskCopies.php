<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
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

    public function __construct(protected SandboxProvider $provider, protected SandboxUpdater $updater) {}

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
            $branch = $this->run($copy, ['/opt/zap/fork', 'branch', self::branch($task)]);
            // The app's dev server (.zap/dev) and its services came with the files; start them.
            $this->provider->exec($copy->external_id, ['/opt/zap/restart']);

            $task->update(['base_commit' => trim($branch) ?: null, 'applied_at' => null]);

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
     * Data services (databases, caches, queues) the app's settings point at outside the sandbox.
     *
     * @return list<string>
     */
    public function externalServices(Sandbox $sandbox): array
    {
        try {
            $result = $this->provider->exec($sandbox->external_id, ['/opt/zap/fork', 'services']);
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
        $dir = '/tmp/zap-fork-'.Str::lower(Str::random(8));

        $this->provider->exec($main->external_id, ['/opt/zap/fork', 'snapshot', $dir, ...SandboxUpdater::KEPT_PATHS], detach: true);

        try {
            $this->waitForSnapshot($main, $dir);

            foreach (SandboxUpdater::KEPT_PATHS as $index => $path) {
                File::ensureDirectoryExists("{$local}/{$index}");
                $this->provider->copyOut($main->external_id, "{$dir}/{$index}", "{$local}/{$index}");
            }
        } finally {
            $this->provider->exec($main->external_id, ['rm', '-rf', $dir]);
        }
    }

    /**
     * @throws SandboxException
     */
    protected function waitForSnapshot(Sandbox $main, string $dir): void
    {
        $deadline = now()->addSeconds(self::SNAPSHOT_TIMEOUT);

        do {
            $check = $this->provider->exec($main->external_id, ['bash', '-c', 'if [ -f "$1/.done" ]; then echo done; elif [ -f "$1/.failed" ]; then cat "$1/.failed"; exit 3; fi', 'check', $dir]);

            if ($check->exitCode === 3) {
                throw new SandboxException("Couldn't copy the app: ".(trim($check->output) ?: 'unknown error'));
            }

            if (trim($check->output) === 'done') {
                return;
            }

            sleep(1);
        } while (now()->lessThan($deadline));

        throw new SandboxException('Copying the app took too long.');
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
        $dir = '/tmp/zap-bundle-'.Str::lower(Str::random(8));
        $local = storage_path('framework/task-bundle-'.uniqid());

        try {
            $this->run($from, ['bash', '-c', 'mkdir -p "$1" && /opt/zap/fork bundle "$1/branch.bundle" "$2"', 'bundle', $dir, $commitMessage]);

            File::ensureDirectoryExists($local);
            $this->provider->copyOut($from->external_id, $dir, $local);
            $this->provider->copyIn($to->external_id, $local, $dir);

            $result = $this->provider->exec($to->external_id, ['/opt/zap/fork', 'merge', "{$dir}/branch.bundle", $mergeMessage]);

            if ($result->exitCode === 3) {
                return array_values(array_filter(array_map('trim', explode("\n", $result->output))));
            }

            if (! $result->successful()) {
                throw new SandboxException("Couldn't merge: ".(strtok(trim($result->errorOutput), "\n") ?: 'git failed'));
            }

            return [];
        } finally {
            File::deleteDirectory($local);

            foreach ([$from, $to] as $sandbox) {
                try {
                    $this->provider->exec($sandbox->external_id, ['rm', '-rf', $dir]);
                } catch (SandboxException) {
                    // A leftover in /tmp does no harm.
                }
            }
        }
    }

    /**
     * Main's sandbox, running and on the current image (so it has the fork tool).
     *
     * @throws SandboxException
     */
    protected function mainSandbox(Project $project): Sandbox
    {
        if (! $project->mainSandboxBusy()) {
            $this->updater->updateIfOutdated($project);
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
