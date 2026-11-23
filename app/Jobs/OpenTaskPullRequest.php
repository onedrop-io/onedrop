<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Enums\TaskSyncStatus;
use App\Models\Task;
use App\Sandbox\GitException;
use App\Sandbox\GitHubPulls;
use App\Sandbox\PullRequestWriter;
use App\Sandbox\SandboxException;
use App\Sandbox\TaskCopies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Open a pull request from a task (GIT-024): commit and push its copy's work to a branch of its own, have the project's
 * AI write a title and description, and keep GitHub's new pull request page, filled in, for the task's bar. The task
 * then watches for the pull request (LinkTaskPullRequest).
 */
class OpenTaskPullRequest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Task $task) {}

    /**
     * Why the task can't open a pull request now, or null when it can.
     */
    public static function problem(Task $task): ?string
    {
        $copy = $task->sandbox()->first();

        if ($task->isPullRequest()) {
            return __('This task is already a pull request\'s: push to it instead.');
        }

        if (! GitHubPulls::available($task->project)) {
            return __('Pull requests need a GitHub repository. Connect one in Source Control.');
        }

        if ($task->sync_status !== null) {
            return __('Wait for the current push or merge to finish.');
        }

        if ($copy?->status !== SandboxStatus::Running || $copy->external_id === null) {
            return __("This task doesn't have its own copy of the app running.");
        }

        if ($task->isWorking()) {
            return __("Wait for the task's agent to finish, or stop it.");
        }

        return null;
    }

    /**
     * Start pushing, unless something's in the way; returns why not, or null when started.
     */
    public static function start(Task $task): ?string
    {
        if (($problem = self::problem($task)) !== null) {
            return $problem;
        }

        $task->update(['sync_status' => TaskSyncStatus::Pushing, 'sync_error' => null]);
        self::dispatch($task);

        return null;
    }

    /**
     * The branch a task's pull request is pushed to: its id keeps it unique, its title makes it readable on GitHub.
     */
    public static function branch(Task $task): string
    {
        $slug = Str::limit(Str::slug($task->title), 40, '');

        return rtrim("onedrop/task-{$task->id}-{$slug}", '-');
    }

    public function handle(TaskCopies $copies, GitHubPulls $pulls, PullRequestWriter $writer): void
    {
        $task = $this->task;
        $project = $task->project;
        // Pushed before: newer work goes to the same branch.
        $branch = $task->pull_request_branch ?? self::branch($task);

        try {
            $base = $pulls->defaultBranch($project);
            $sha = $copies->pushBranch($task, $branch, $task->title);
        } catch (GitException|SandboxException $e) {
            $task->update(['sync_status' => null, 'sync_error' => $e->getMessage()]);
            $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Couldn't push {$branch}: ".$e->getMessage()]);

            return;
        }

        $text = $this->write($task, $writer, $branch, $base);
        $repository = (string) GitHubPulls::repository($project);
        $url = "https://github.com/{$repository}/compare/".rawurlencode($base).'...'.rawurlencode($branch).'?'
            .http_build_query(['quick_pull' => 1, 'title' => $text['title'], 'body' => $text['body']], '', '&', PHP_QUERY_RFC3986);

        $task->update([
            'sync_status' => null,
            'sync_error' => null,
            'pull_request_branch' => $branch,
            'pull_request_base' => $base,
            'pull_request_head_sha' => $sha,
            'pull_request_draft' => ['title' => $text['title'], 'body' => $text['body'], 'url' => $url],
        ]);
        $task->messages()->create(['role' => MessageRole::Activity, 'content' => "Pushed {$branch} (".substr($sha, 0, 7).')']);

        LinkTaskPullRequest::dispatch($task, $branch)->delay(now()->addSeconds(LinkTaskPullRequest::INTERVAL));
    }

    /**
     * The project's AI's title and description, from the copy's commits that the base doesn't have; the task's own
     * title and notes when they can't be compared.
     *
     * @return array{title: string, body: string}
     */
    protected function write(Task $task, PullRequestWriter $writer, string $branch, string $base): array
    {
        $copy = $task->sandbox()->first();

        try {
            if ($copy !== null) {
                $written = $writer->write($task->project, $copy, $branch, $base);

                return ['title' => $written['title'], 'body' => $written['body']];
            }
        } catch (GitException|SandboxException) {
            // Falls back to the task's own words below.
        }

        return ['title' => $task->title, 'body' => (string) $task->description];
    }

    /**
     * Never leave the task showing "Pushing…" if the job itself crashes.
     */
    public function failed(?Throwable $exception): void
    {
        $this->task->update(['sync_status' => null, 'sync_error' => __('Something went wrong. Try again.')]);
    }
}
