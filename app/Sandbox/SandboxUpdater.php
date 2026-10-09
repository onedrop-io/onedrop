<?php

namespace App\Sandbox;

use App\Enums\SandboxMovePhase;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use App\Models\SandboxMove;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps existing projects' sandboxes current (SBX-002): copies changed tool files into a running sandbox (SandboxTools),
 * or moves it to a fresh one from the current image, keeping its files (SandboxMover; without them, the code comes
 * back from its backup, see ProjectBackups). Used by sandbox:recreate, sandbox:update, UpdateSandbox and agent runs.
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

    /** Longest copying tool files in may hold the project's update lock, in seconds. */
    protected const LOCK_SECONDS = 120;

    public function __construct(protected SandboxProvider $provider, protected SandboxTools $tools, protected SandboxMover $mover) {}

    /**
     * Whether the project's running sandbox needs a new sandbox to be current: it lives on another provider than the
     * project's (the install's, or its computer's: DESK-010), or a newer image changed what its tools can't bring in
     * place (see bringUpToDate()).
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
     * for someone waiting on it. The move is queued, or with $wait (a command) waited for; $options go to the move
     * (see SandboxMover::start()). Returns whether this call changed the sandbox or started moving it.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws SandboxException
     */
    public function updateIfOutdated(Project $project, bool $rebuild = true, bool $wait = false, string $reason = 'update', array $options = []): bool
    {
        $move = null;

        $changed = Cache::lock($this->lockName($project), self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($project, $rebuild, $wait, $reason, $options, &$move) {
            $sandbox = $this->runningSandbox($project);

            // One under way already brings it up to date.
            if ($sandbox === null || SandboxMove::query()->active()->where('sandbox_id', $sandbox->id)->exists()) {
                return false;
            }

            $plan = $this->plan($sandbox);

            if (is_array($plan) && $this->tools->install($this->provider, $sandbox->external_id, $plan)) {
                return true;
            }

            if ($rebuild && ($plan === 'rebuild' || (is_array($plan) && $this->provider->isOutdated($sandbox->external_id)))) {
                $move = $this->mover->start($sandbox, $reason, options: $options, queue: ! $wait);

                return true;
            }

            return false;
        });

        if ($move && $wait) {
            $this->finished($this->mover->run($move));
        }

        return $changed;
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
        if ($sandbox->provider !== $sandbox->project->sandboxProvider()) {
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

    protected function runningSandbox(Project $project): ?Sandbox
    {
        $sandbox = $project->sandbox()->first();

        return $sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null ? $sandbox : null;
    }

    /**
     * Whether the project's main sandbox is being replaced by a new one now (not just queued), for the workspace.
     */
    public static function isUpdating(Project $project): bool
    {
        $sandbox = $project->sandbox;

        return $sandbox !== null && SandboxMover::isMoving($sandbox);
    }

    /**
     * Replace the project's sandbox with a fresh one now, and wait for it (commands). With $from, the new sandbox
     * gets that snapshot's files instead of the old sandbox's (sandbox:restore).
     *
     * @param  (Closure(string): void)|null  $report  progress messages
     *
     * @throws SandboxException when the move failed (the project stays on its old sandbox)
     */
    public function recreate(Project $project, bool $keepFiles = false, ?Closure $report = null, ?ProjectSnapshot $from = null): Sandbox
    {
        $sandbox = $project->sandbox()->firstOrCreate([], ['provider' => $project->sandboxProvider(), 'status' => SandboxStatus::Creating]);
        $move = $this->mover->start($sandbox, $from ? 'restore' : 'recreate', $keepFiles, $from, queue: false);

        $this->finished($this->mover->run($move, $report));

        return $sandbox->fresh();
    }

    /**
     * @throws SandboxException
     */
    protected function finished(SandboxMove $move): void
    {
        if ($move->phase === SandboxMovePhase::Failed) {
            throw new SandboxException((string) $move->error);
        }
    }

    protected function lockName(Project $project): string
    {
        return "sandbox-update:{$project->id}";
    }
}
