<?php

namespace App\Sandbox\Hosting;

use App\Enums\DeploymentStatus;
use App\Enums\PublishTarget;
use App\Enums\SandboxStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;

/**
 * What the sandbox has that the hosted app doesn't yet (HOST-004): the commits since the one the live deployment
 * shipped, read from the workspace's git after each turn and kept on the project for the Publish panel. A project that
 * leaves its changes uncommitted (SCM-003) counts its private checkpoints instead: each agent turn, and edits made
 * outside it.
 */
class HostingChanges
{
    /** Commits listed; the count covers them all. */
    public const SHOWN = 10;

    /**
     * Prints the count, then one "sha<TAB>subject" line per commit, newest first, from $ONEDROP_FROM to $ONEDROP_TO (or
     * HEAD when it isn't there); nothing when $ONEDROP_FROM is gone.
     */
    protected const SCRIPT = <<<'BASH'
        export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="*"
        cd /workspace 2>/dev/null && git cat-file -e "$ONEDROP_FROM^{commit}" 2>/dev/null || exit 0
        to="$ONEDROP_TO"; git rev-parse -q --verify "$to" >/dev/null || to=HEAD
        git rev-list --count "$ONEDROP_FROM..$to"
        git log --format='%h%x09%s' -n "$ONEDROP_SHOWN" "$ONEDROP_FROM..$to"
        BASH;

    /** Saves the files as a private checkpoint when they changed, and prints the newest (SCM-002). */
    protected const SNAPSHOT = <<<'BASH'
        export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="*"
        grep -q -- --snapshot /opt/onedrop/checkpoint 2>/dev/null || { git -C /workspace rev-parse --verify -q HEAD; exit; }
        ONEDROP_CHECKPOINT_KIND=edits /opt/onedrop/checkpoint --snapshot </dev/null
        BASH;

    public function __construct(protected SandboxProvider $sandboxes) {}

    /**
     * The workspace's current commit, or null without one. A project that leaves its changes uncommitted ships its
     * files as they are, so that's a private checkpoint of them.
     *
     * @throws SandboxException
     */
    public function head(Project $project, Sandbox $sandbox): ?string
    {
        $result = $this->sandboxes->exec($sandbox->external_id, ['bash', '-c', $project->commit_turns
            ? 'export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="*"; git -C /workspace rev-parse --verify -q HEAD'
            : self::SNAPSHOT]);
        $sha = trim($result->output);

        return $result->successful() && preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }

    /**
     * Read what changed since the live deployment and keep it on the project; clear it when the project isn't hosted
     * or nothing changed.
     *
     * @throws SandboxException
     */
    public function refresh(Project $project): void
    {
        $live = $this->live($project);
        $sandbox = $project->sandbox()->first();

        if ($live?->commit === null || $sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            $this->keep($project, null);

            return;
        }

        $result = $this->sandboxes->exec($sandbox->external_id, ['bash', '-c', self::SCRIPT], [
            'ONEDROP_FROM' => $live->commit,
            'ONEDROP_TO' => $project->commit_turns ? 'HEAD' : 'refs/onedrop/checkpoints',
            'ONEDROP_SHOWN' => (string) self::SHOWN,
        ]);
        $lines = preg_split('/\R/', trim($result->output)) ?: [];
        $count = (int) array_shift($lines);

        $this->keep($project, $count > 0 ? [
            'count' => $count,
            'commits' => array_values(array_map(function (string $line): array {
                [$sha, $message] = explode("\t", $line, 2) + [1 => ''];

                return ['sha' => $sha, 'message' => $message];
            }, array_filter($lines))),
        ] : null);
    }

    /**
     * The deployment running now, when the project is published to hosting.
     */
    public function live(Project $project): ?Deployment
    {
        if ($project->publish_target !== PublishTarget::Hosting || $project->publish_status === null) {
            return null;
        }

        return $project->deployments()->where('status', DeploymentStatus::Live)->latest('id')->first();
    }

    /**
     * @param  array{count: int, commits: list<array{sha: string, message: string}>}|null  $changes
     */
    protected function keep(Project $project, ?array $changes): void
    {
        if ($project->hosting_changes !== $changes) {
            $project->update(['hosting_changes' => $changes]);
        }
    }
}
