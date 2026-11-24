<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Enums\TaskSyncStatus;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * A pull request's preview on GitHub (GIT-028): a Deployment of its newest commit, whose status follows the task's
 * copy, and one comment kept up to date with the link. Written with the project's GitHub App installation or token;
 * when GitHub refuses, the task says why and the preview itself still works.
 */
class GitHubPreviews
{
    /** Marks the comment as OneDrop's, so it's found again. */
    public const MARKER = '<!-- onedrop-preview -->';

    public function __construct(protected GitHubPulls $pulls, protected Gateway $gateway) {}

    /**
     * Whether the task's preview belongs on GitHub: a pull request's task, in a project whose pull requests can be
     * read, with an address to link to.
     */
    public function applies(Task $task): bool
    {
        return $task->isPullRequest() && $this->gateway->enabled() && GitHubPulls::available($task->project);
    }

    /**
     * Where the preview is now: in_progress, success, failure, or inactive once the pull request is merged or closed.
     */
    public static function state(Task $task): string
    {
        $copy = $task->sandbox()->first();

        return match (true) {
            $task->pull_request_state !== null => 'inactive',
            $copy === null || $copy->status === SandboxStatus::Failed => 'failure',
            $copy->status === SandboxStatus::Creating || in_array($task->sync_status, [TaskSyncStatus::Forking, TaskSyncStatus::Pulling], true) => 'in_progress',
            default => 'success',
        };
    }

    /**
     * Bring the Deployment and the comment up to date with the task.
     */
    public function update(Task $task): void
    {
        if (! $this->applies($task)) {
            return;
        }

        $state = self::state($task);

        if ($state !== 'inactive' && $task->preview_key === null) {
            $task->update(['preview_key' => Str::random(40)]);
        }

        try {
            $this->deploy($task, $state);
            $this->comment($task, $state);
            $task->update(['preview_error' => null]);
        } catch (GitException $e) {
            $task->update(['preview_error' => Str::limit($e->getMessage(), 250)]);
        }
    }

    /**
     * A Deployment for the newest commit (a new one when the commit changed), with the state as its status.
     *
     * @throws GitException
     */
    protected function deploy(Task $task, string $state): void
    {
        $project = $task->project;
        $repository = (string) GitHubPulls::repository($project);
        $sha = $task->pull_request_head_sha;

        if ($state !== 'inactive' && $sha !== null && $task->preview_deployment_sha !== $sha) {
            $deployment = $this->pulls->check($this->pulls->api($project)->post("https://api.github.com/repos/{$repository}/deployments", [
                'ref' => $sha,
                'environment' => "OneDrop preview #{$task->pull_request_number}",
                'description' => 'A running copy of the app with this pull request',
                'transient_environment' => true,
                'production_environment' => false,
                'auto_merge' => false,
                'required_contexts' => [],
            ]), 'Deployments', 'Read and write')->json('id');

            $task->update(['preview_deployment_id' => (int) $deployment, 'preview_deployment_sha' => $sha]);
        }

        if ($task->preview_deployment_id === null) {
            return;
        }

        $this->pulls->check($this->pulls->api($project)->post("https://api.github.com/repos/{$repository}/deployments/{$task->preview_deployment_id}/statuses", array_filter([
            'state' => $state,
            'environment_url' => $state === 'inactive' ? null : $this->gateway->pullRequestLink($task),
            'log_url' => route('projects.tasks.show', [$project, $task]),
            'description' => match ($state) {
                'in_progress' => 'Updating the preview',
                'success' => 'The preview is running',
                'failure' => 'The preview couldn\'t be made',
                default => 'The preview was removed',
            },
            // Earlier commits' deployments stop showing as active.
            'auto_inactive' => true,
        ], fn ($value) => $value !== null)), 'Deployments', 'Read and write');
    }

    /**
     * The one comment on the pull request: edited in place, posted again if someone deleted it.
     *
     * @throws GitException
     */
    protected function comment(Task $task, string $state): void
    {
        $project = $task->project;
        $repository = (string) GitHubPulls::repository($project);
        $body = $this->body($task, $state);

        if ($task->preview_comment_id !== null) {
            $response = $this->pulls->api($project)->patch("https://api.github.com/repos/{$repository}/issues/comments/{$task->preview_comment_id}", ['body' => $body]);

            if ($response->status() !== 404) {
                $this->pulls->check($response, 'Pull requests', 'Read and write');

                return;
            }
        }

        $id = $this->pulls->check(
            $this->pulls->api($project)->post("https://api.github.com/repos/{$repository}/issues/{$task->pull_request_number}/comments", ['body' => $body]),
            'Pull requests',
            'Read and write',
        )->json('id');

        $task->update(['preview_comment_id' => (int) $id]);
    }

    /**
     * The comment's text: the link, the commit it runs, its state, and who can open it.
     */
    public function body(Task $task, string $state): string
    {
        $short = $task->pull_request_head_sha ? '`'.substr($task->pull_request_head_sha, 0, 7).'`' : 'this pull request';
        $link = $this->gateway->pullRequestLink($task);
        $taskUrl = route('projects.tasks.show', [$task->project, $task]);

        $status = match ($state) {
            'in_progress' => "Updating to {$short}. The link works now and shows the newest commit once it's ready.",
            'success' => "Running {$short}.",
            'failure' => "Couldn't be made for {$short}. [See why in OneDrop]({$taskUrl}).",
            default => 'Removed: this pull request was '.($task->pull_request_state ?? 'closed').'.',
        };

        $lines = [self::MARKER, '**OneDrop preview**', '', $status];

        if ($state !== 'inactive' && $link !== null) {
            $lines[] = '';
            $lines[] = "[Open the preview]({$link})";
            $lines[] = '';
            $lines[] = '<sub>Anyone with this link can open the app. It runs on a copy of the project\'s Main data, not production. Updated '.now()->utc()->format('Y-m-d H:i').' UTC.</sub>';
        }

        return implode("\n", $lines);
    }
}
