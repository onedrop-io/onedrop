<?php

namespace App\Sandbox;

use App\Models\Task;
use App\Sandbox\Agents\AgentQueue;
use Illuminate\Support\Str;

/**
 * Asks a pull request's task's agent to fix its failed checks (GIT-015): their names and the end of their logs,
 * with the same rules as the repository's own CI fixes (the root cause; no skipped, deleted or loosened tests,
 * no sleeps or longer timeouts). Sent like a user's message, so it waits while the agent is working.
 */
class PullRequestFixes
{
    /** Fixes sent in a row without the checks passing before automatic fixing stops. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(protected GitHubPulls $pulls, protected AgentQueue $queue) {}

    /**
     * Send the failed checks among $checks (the newest commit's, when null) to the task's agent; false when none failed.
     *
     * @param  list<array<string, mixed>>|null  $checks
     *
     * @throws GitException
     */
    public function send(Task $task, ?array $checks = null, ?string $sha = null): bool
    {
        $project = $task->project;
        $sha ??= $task->pull_request_head_sha ?? $this->pulls->find($project, (int) $task->pull_request_number)['head_sha'];
        $failed = GitHubPulls::failed($checks ?? $this->pulls->checks($project, $sha, fresh: true));

        if ($failed === []) {
            return false;
        }

        $this->queue->send($task, $this->prompt($task, $failed, $sha));

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $failed
     */
    public function prompt(Task $task, array $failed, string $sha): string
    {
        $budget = GitHubPulls::LOGS_CHARACTERS;
        $sections = [];

        foreach ($failed as $check) {
            $log = $budget > 0 ? $this->pulls->log($task->project, $check, min(GitHubPulls::LOG_CHARACTERS, $budget)) : null;
            $budget -= strlen((string) $log);
            $section = "### {$check['name']} ({$check['state']})".($check['url'] ? "\n{$check['url']}" : '');
            $sections[] = $log ? $section."\n\nThe end of its log:\n\n```\n".Str::replace('```', "'''", trim($log))."\n```" : $section;
        }

        $count = count($failed);
        $pushing = $task->pull_request_push
            ? 'Your work is pushed to the pull request when this turn ends; don\'t push it yourself.'
            : 'Commit your work; the user pushes it to the pull request.';

        return "The checks on pull request #{$task->pull_request_number} ({$task->pull_request_branch} into {$task->pull_request_base}) failed on commit ".substr($sha, 0, 7).': '
            .trans_choice(':count check failed.|:count checks failed.', $count, ['count' => $count])
            ."\n\n".implode("\n\n", $sections)
            ."\n\nFind the root cause and fix it. Run the failing command here first to see the failure, then again to confirm the fix. "
            .'Fix the code (or a flaky test\'s real cause), not the checks: don\'t skip, delete or loosen tests, and don\'t add sleeps or longer timeouts. '
            .'If it fails for a reason outside the code (a missing secret, an outage), say so instead of changing anything. '
            .$pushing;
    }
}
