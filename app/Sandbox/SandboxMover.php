<?php

namespace App\Sandbox;

use App\Enums\MessageRole;
use App\Enums\MoveSource;
use App\Enums\OldSandboxStatus;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\SandboxMovePhase;
use App\Enums\SandboxStatus;
use App\Jobs\CreateSandbox;
use App\Jobs\MoveProjectSandbox;
use App\Jobs\PublishProject;
use App\Jobs\RecoverSandbox;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use App\Models\SandboxMove;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Publishing\Publishers;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Moves a sandbox (a project's main one, a computer's, or a task's copy) to a new one with its files (SBX-005): to the
 * provider its project should run on, a newer image, or a computer. A move goes in short steps, each advanced by a
 * queue job (MoveProjectSandbox) or by a command waiting on it, so none runs longer than Laravel Cloud's Flex queues
 * allow; the slow parts (packing and unpacking files) run in the sandboxes in the background (ProjectSnapshots).
 *
 * The files come from a fresh snapshot of the old sandbox when it answers, else from the latest snapshot or the code
 * backup (SBX-013). A sandbox that didn't answer is never deleted while it may hold newer files: RecoverSandbox checks
 * on it until it does.
 */
class SandboxMover
{
    /** A move still not done after this long is given up on. */
    public const GIVE_UP_MINUTES = 60;

    /** How long the old sandbox gets to answer before the move goes on without it. */
    public const ANSWER_SECONDS = 20;

    /** Least time a step needs left to make a sandbox, so a provider that's slow to make one isn't cut off. */
    public const CREATE_SECONDS = 45;

    public function __construct(
        protected SandboxProvider $provider,
        protected ProjectSnapshots $snapshots,
        protected ProjectBackups $backups,
        protected Publishers $publishers,
        protected SandboxWaitLimit $limit,
    ) {}

    /**
     * Sandboxes on another provider than their project should run on (turned off, or no longer first), with no move
     * under way.
     *
     * @return Collection<int, Sandbox>
     */
    public function misplaced(): Collection
    {
        return Sandbox::query()
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->whereNotIn('id', SandboxMove::query()->active()->select('sandbox_id'))
            ->with('project')
            ->get()
            ->filter(fn (Sandbox $sandbox) => $sandbox->project !== null && $sandbox->provider !== $sandbox->project->sandboxProvider())
            ->values();
    }

    /**
     * Start moving every misplaced sandbox, now. Returns how many.
     */
    public function moveMisplaced(): int
    {
        return $this->misplaced()->each(fn (Sandbox $sandbox) => $this->start($sandbox, 'provider'))->count();
    }

    /**
     * Start moving a sandbox (or return the move already under way), queued unless $queue is false (a command runs it
     * with run()). With $from, the new sandbox gets that snapshot's files instead of the old sandbox's.
     *
     * @param  array<string, mixed>  $options  suspend: suspend the new sandbox afterwards; revert_device_id: the computer
     *                                         to go back to if a move to another fails (DESK-010)
     */
    public function start(Sandbox $sandbox, string $reason, bool $keepFiles = true, ?ProjectSnapshot $from = null, array $options = [], bool $queue = true): SandboxMove
    {
        $move = Cache::lock("sandbox-move:{$sandbox->id}", 10)->block(10, function () use ($sandbox, $reason, $keepFiles, $from, $options) {
            return SandboxMove::query()->active()->where('sandbox_id', $sandbox->id)->first()
                ?? SandboxMove::query()->create([
                    'project_id' => $sandbox->project_id,
                    'sandbox_id' => $sandbox->id,
                    'reason' => $reason,
                    'keep_files' => $keepFiles,
                    'phase' => SandboxMovePhase::Starting,
                    'from_provider' => $sandbox->external_id ? $sandbox->provider : null,
                    'from_external_id' => $sandbox->external_id,
                    'to_provider' => $sandbox->project->sandboxProvider(),
                    'snapshot_id' => $from?->id,
                    'source' => $from ? MoveSource::Snapshot : null,
                    'options' => $options,
                ]);
        });

        if ($queue && $move->wasRecentlyCreated) {
            MoveProjectSandbox::dispatch($move);
        }

        return $move;
    }

    /**
     * Take a move all the way, waiting on the background work (for commands). $report gets each new note.
     *
     * @param  (Closure(string): void)|null  $report
     */
    public function run(SandboxMove $move, ?Closure $report = null): SandboxMove
    {
        $reported = 0;

        do {
            $over = $this->step($move);

            foreach (array_slice($move->messages ?? [], $reported) as $message) {
                $report?->__invoke($message);
            }

            $reported = count($move->messages ?? []);

            if (! $over) {
                Sleep::for(2)->seconds();
            }
        } while (! $over);

        return $move;
    }

    /**
     * Advance the move as far as it can go now. Returns true once it's over (done or failed), false while it waits on
     * something (background work, the agent's turn, a provider that's full): check again in a few seconds.
     */
    public function step(SandboxMove $move): bool
    {
        while ($move->phase->isActive()) {
            try {
                $advanced = match ($move->phase) {
                    SandboxMovePhase::Starting => $this->begin($move),
                    SandboxMovePhase::Snapshotting => $this->snapshotted($move),
                    SandboxMovePhase::Creating => $this->create($move),
                    SandboxMovePhase::Restoring => $this->restore($move),
                    SandboxMovePhase::Finishing => $this->finish($move),
                };
            } catch (TemporarySandboxException $e) {
                if ($move->created_at->lt(now()->subMinutes(self::GIVE_UP_MINUTES))) {
                    $this->fail($move, $e);

                    return true;
                }

                return false;
            } catch (Throwable $e) {
                $this->fail($move, $e);

                return true;
            }

            if (! $advanced) {
                return false;
            }
        }

        return true;
    }

    /**
     * Give up on the move: delete the new sandbox, and leave the project on its old one, which carries on.
     */
    public function fail(SandboxMove $move, Throwable $e): void
    {
        report($e);

        if ($move->to_external_id) {
            try {
                $this->provider->destroy($move->to_external_id);
            } catch (Throwable $destroyFailed) {
                report($destroyFailed);
            }
        }

        if ($move->from_answered && ($move->options['frozen'] ?? false)) {
            try {
                $this->provider->start((string) $move->from_external_id);
            } catch (Throwable $startFailed) {
                report($startFailed);
            }
        }

        if ($move->snapshot?->task_id !== null && $move->source === MoveSource::Fresh) {
            $this->snapshots->forget($move->snapshot);
        }

        // A move to a computer that failed goes back where it ran (DESK-010).
        if (array_key_exists('revert_device_id', $move->options ?? [])) {
            $move->project->update(['device_id' => $move->options['revert_device_id']]);
        }

        $move->update([
            'phase' => SandboxMovePhase::Failed,
            'error' => $e instanceof SandboxException ? $e->getMessage() : __('Something went wrong. Try again.'),
            'work' => null,
            'finished_at' => now(),
        ]);
    }

    /**
     * Check on a sandbox a project moved off while it didn't answer (SBX-013). When it answers, files newer than what
     * the project got are saved as a recovered snapshot, and then it's deleted. Returns how many seconds to wait before
     * checking again, or null when there's nothing more to do.
     *
     * @throws SandboxException
     */
    public function recover(SandboxMove $move): ?int
    {
        if ($move->old_status !== OldSandboxStatus::Waiting || $move->from_external_id === null) {
            return null;
        }

        $old = $this->oldSandbox($move);
        $pending = isset($move->work['recovering']) ? ProjectSnapshot::query()->whereKey($move->work['recovering'])->first() : null;

        if ($pending === null) {
            if (! $this->answers($move->from_external_id)) {
                return RecoverSandbox::CHECK_MINUTES * 60;
            }

            // Compared with what the project got, so only newer files make a snapshot.
            $pending = $this->snapshots->begin($old, ProjectSnapshot::RECOVERED, $move->snapshot);

            if ($pending === null || $pending->is($move->snapshot) || $pending->reason !== ProjectSnapshot::RECOVERED) {
                $move->note(__('The old sandbox answered again, with nothing newer than what the project got; it was deleted.'));
                $this->removeOld($move);

                return null;
            }

            $move->update(['work' => ['recovering' => $pending->id]]);
        }

        if (! $this->snapshots->check($pending)) {
            return MoveProjectSandbox::CHECK_SECONDS;
        }

        $move->update(['recovered_snapshot_id' => $pending->id, 'old_status' => OldSandboxStatus::Recovered, 'work' => null]);
        $move->note(__('The old sandbox answered again; its newer files were saved, and can be restored from Settings → Sandboxes.'));
        $this->removeOld($move, OldSandboxStatus::Recovered);

        return null;
    }

    /**
     * Moves for Settings → Sandboxes: under way, failed ones not tried again since, old sandboxes still being checked
     * on, and recovered files not yet restored or dismissed (SBX-013).
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $moves = SandboxMove::query()
            ->with(['project:id,name', 'sandbox.task:id,title', 'snapshot:id,created_at', 'recoveredSnapshot:id,created_at'])
            ->where(fn ($query) => $query->active()
                ->orWhere(fn ($failed) => $failed->where('phase', SandboxMovePhase::Failed)->where('created_at', '>', now()->subDays(7)))
                ->orWhereIn('old_status', [OldSandboxStatus::Waiting, OldSandboxStatus::Recovered]))
            ->latest('id')
            ->get();

        $latest = SandboxMove::query()->whereIn('sandbox_id', $moves->pluck('sandbox_id')->unique())
            ->groupBy('sandbox_id')->selectRaw('sandbox_id, MAX(id) as id')->pluck('id', 'sandbox_id');

        return array_values($moves
            // A failed move counts until the same sandbox moves again.
            ->reject(fn (SandboxMove $move) => $move->phase === SandboxMovePhase::Failed && $latest[$move->sandbox_id] > $move->id)
            ->reject(fn (SandboxMove $move) => $move->old_status === OldSandboxStatus::Recovered && ($move->recoveredSnapshot === null || isset($move->options['settled_at'])))
            ->map(fn (SandboxMove $move) => [
                'id' => $move->id,
                'project' => ['id' => $move->project_id, 'name' => $move->project?->name],
                'task' => $move->sandbox?->task?->title,
                'reason' => $move->reason,
                'from' => $move->from_provider ? $this->label($move->from_provider) : null,
                'to' => $move->to_provider ? $this->label($move->to_provider) : null,
                'phase' => $move->phase->value,
                'source' => $move->source?->value,
                'snapshot_at' => $move->snapshot?->created_at?->toIso8601String(),
                'old_status' => $move->old_status?->value,
                'recovered_at' => $move->recoveredSnapshot?->created_at?->toIso8601String(),
                'error' => $move->error,
                'message' => collect($move->messages ?? [])->last(),
                'started_at' => $move->created_at?->toIso8601String(),
                'finished_at' => $move->finished_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Whether a sandbox answers a trivial command within ANSWER_SECONDS.
     */
    public function answers(string $externalId): bool
    {
        try {
            return $this->limit->within(self::ANSWER_SECONDS, fn () => $this->provider->exec($externalId, ['true'])->successful());
        } catch (SandboxException) {
            return false;
        }
    }

    /**
     * Whether a project's sandbox (its main one, or a task's copy) is being moved now: not just queued, or waiting
     * for the agent's turn to end.
     */
    public static function isMoving(Sandbox $sandbox): bool
    {
        return SandboxMove::query()->active()->where('sandbox_id', $sandbox->id)->where('phase', '!=', SandboxMovePhase::Starting)->exists();
    }

    /**
     * Ask the old sandbox, then pick where the files come from.
     *
     * @throws SandboxException
     */
    protected function begin(SandboxMove $move): bool
    {
        $sandbox = $move->sandbox;
        $answered = $move->from_external_id !== null && $this->answers($move->from_external_id);
        $move->update(['from_answered' => $answered]);

        // A turn in a sandbox that answers ends first; nothing it does after the snapshot would reach the new one.
        if ($answered && $sandbox->project()->firstOrFail()->busyIn($sandbox)) {
            if (($move->work['waiting'] ?? false) === false) {
                $move->update(['work' => ['waiting' => true]]);
                $move->note(__('Waiting for the agent to finish its turn.'));
            }

            return false;
        }

        $move->work = null;

        if (! $move->keep_files) {
            $source = $sandbox->task_id === null && $this->backups->exists($move->project) ? MoveSource::Backup : MoveSource::None;
            $move->update(['source' => $source, 'phase' => SandboxMovePhase::Creating]);

            return true;
        }

        if ($move->snapshot_id !== null) {
            $move->update(['source' => MoveSource::Snapshot, 'phase' => SandboxMovePhase::Creating]);

            return true;
        }

        if (! $answered) {
            return $this->useEarlierFiles($move, $move->from_external_id === null
                ? __('The old sandbox is gone.')
                : __(':provider didn\'t answer.', ['provider' => $this->label($move->from_provider)]));
        }

        try {
            $snapshot = $this->snapshots->begin($sandbox, 'update');
        } catch (TemporarySandboxException $e) {
            throw $e;
        } catch (SandboxException $e) {
            report($e);

            return $this->useEarlierFiles($move, __('Couldn\'t take a snapshot of the old sandbox (:error).', ['error' => $e->getMessage()]));
        }

        if ($snapshot === null) {
            // No snapshots here (a local disk and a remote sandbox): the files go through the platform.
            $move->update(['source' => MoveSource::Copy, 'phase' => SandboxMovePhase::Creating]);

            return true;
        }

        $move->update(['source' => MoveSource::Fresh, 'snapshot_id' => $snapshot->id, 'phase' => SandboxMovePhase::Snapshotting]);
        $move->note(__('Taking a snapshot of the old sandbox.'));

        return true;
    }

    /**
     * Wait for the old sandbox's snapshot, then stop its app: nothing it does afterwards would reach the new sandbox.
     *
     * @throws SandboxException
     */
    protected function snapshotted(SandboxMove $move): bool
    {
        try {
            if (! $this->snapshots->check($move->snapshot)) {
                return false;
            }
        } catch (TemporarySandboxException $e) {
            throw $e;
        } catch (SandboxException $e) {
            report($e);

            return $this->useEarlierFiles($move, __('Couldn\'t take a snapshot of the old sandbox (:error).', ['error' => $e->getMessage()]));
        }

        try {
            $this->provider->pause((string) $move->from_external_id);
            $move->options = [...($move->options ?? []), 'frozen' => true];
        } catch (SandboxException $e) {
            // Its files are in the snapshot either way.
            report($e);
        }

        $move->update(['phase' => SandboxMovePhase::Creating]);

        return true;
    }

    /**
     * Without the old sandbox's files: its latest snapshot, else its code backup. A project with neither stays where
     * it is, since a new sandbox would have none of its files.
     *
     * @throws SandboxException
     */
    protected function useEarlierFiles(SandboxMove $move, string $why): bool
    {
        $sandbox = $move->sandbox;
        $latest = $sandbox->task_id === null ? $this->snapshots->latest($move->project) : null;

        if ($latest) {
            $move->update(['source' => MoveSource::Snapshot, 'snapshot_id' => $latest->id, 'phase' => SandboxMovePhase::Creating]);
            $move->note(trim($why.' '.__('Using its latest snapshot, from :time.', ['time' => $latest->created_at->toDayDateTimeString().' UTC'])));

            return true;
        }

        if ($sandbox->task_id === null && $this->backups->exists($move->project)) {
            $move->update(['source' => MoveSource::Backup, 'snapshot_id' => null, 'phase' => SandboxMovePhase::Creating]);
            $move->note(trim($why.' '.__('There\'s no snapshot of it, so only its code comes back, from its backup.')));

            return true;
        }

        throw new SandboxException(trim($why.' '.__('There\'s no snapshot or backup of its files to move, so it stays where it is until it answers.')));
    }

    /**
     * Make the new sandbox, with the same settings a first one gets.
     *
     * @throws SandboxException
     */
    protected function create(SandboxMove $move): bool
    {
        if ($move->to_external_id === null) {
            if (($this->limit->secondsLeft() ?? PHP_INT_MAX) < self::CREATE_SECONDS) {
                return false;
            }

            $sandbox = $move->sandbox;
            $id = $this->provider->create((new CreateSandbox($move->project, $sandbox->task))->spec($sandbox));
            $move->update(['to_external_id' => $id, 'to_provider' => $move->project->sandboxProvider()]);
            $move->note(__('Made a new sandbox on :provider.', ['provider' => $this->label($move->to_provider)]));

            try {
                app(OrganizationSecrets::class)->sync($this->newSandbox($move));
            } catch (SandboxException $e) {
                // The agent's next run tries again.
                report($e);
            }
        }

        $move->update(['phase' => SandboxMovePhase::Restoring]);

        return true;
    }

    /**
     * Put the files into the new sandbox and start the app.
     *
     * @throws SandboxException
     */
    protected function restore(SandboxMove $move): bool
    {
        $new = $this->newSandbox($move);

        switch ($move->source) {
            case MoveSource::Fresh:
            case MoveSource::Snapshot:
                if (($move->work['restoring'] ?? false) === false) {
                    if (! $this->snapshots->canRestore($new)) {
                        throw new SandboxException(__('The new sandbox can\'t get the project\'s snapshot.'));
                    }

                    $done = $this->snapshots->startRestore($new, $move->snapshot);
                    $move->update(['work' => ['restoring' => true]]);

                    if (! $done) {
                        return false;
                    }
                } elseif (! $this->snapshots->restored($new, $move->snapshot)) {
                    return false;
                }

                $this->startApp($move);
                $move->note(__('Restored the project\'s files into the new sandbox.'));
                break;

            case MoveSource::Copy:
                $this->copyThroughPlatform($move);
                $this->startApp($move);
                $move->note(__('Copied files into the new sandbox.'));
                break;

            case MoveSource::Backup:
                try {
                    if ($this->backups->restore($move->project, $new)) {
                        $move->note(__('Restored the code from its backup.'));
                    }
                } catch (SandboxException $e) {
                    // The backup stays on its disk to try again; a new sandbox without it still works.
                    report($e);
                    $move->note(__('Couldn\'t restore the code from its backup: :error', ['error' => $e->getMessage()]));
                }
                break;

            default:
                break;
        }

        $move->update(['phase' => SandboxMovePhase::Finishing, 'work' => null]);

        return true;
    }

    /**
     * Point the project at the new sandbox, publish it again, and deal with the old one.
     *
     * @throws SandboxException
     */
    protected function finish(SandboxMove $move): bool
    {
        $sandbox = $move->sandbox;
        $project = $move->project;
        $main = $sandbox->task_id === null;
        // A hosted app runs off the sandbox (HOST-001), so a new sandbox doesn't touch it.
        $republishAs = $main && $project->publish_status === PublishStatus::Live && $project->publish_target !== PublishTarget::Hosting
            ? $project->publish_visibility
            : null;

        // The publish sidecar shares the old sandbox's network, so it goes too; republished below.
        if ($main && $project->publish_status && $project->publish_target !== PublishTarget::Hosting) {
            $this->publishers->forProject($project)->stop($project);
            $project->update(['publish_status' => null, 'published_url' => null, 'published_default_url' => null]);
        }

        $sandbox->forceFill([
            'provider' => $move->to_provider,
            'external_id' => $move->to_external_id,
            'status' => SandboxStatus::Running,
            ...CreateSandbox::addresses($this->provider, (string) $move->to_external_id),
            'error' => null,
            'suspended_at' => null,
            'stopped_at' => null,
        ])->save();

        // Without the old files the agent's history is gone, so its sessions start over.
        if (in_array($move->source, [MoveSource::Backup, MoveSource::None], true)) {
            if ($main) {
                $project->update(['agent_session_id' => null]);
                $project->tasks()->reorder()->update(['agent_session_id' => null]);
            } else {
                $sandbox->task?->update(['agent_session_id' => null]);
            }
        }

        if ($republishAs) {
            $project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => $republishAs, 'publish_error' => null]);
            PublishProject::dispatch($project);
            $move->note(__('Publishing it again.'));
        }

        if ($move->from_external_id !== null) {
            if ($move->from_answered) {
                $this->removeOld($move);
            } else {
                $move->update(['old_status' => OldSandboxStatus::Waiting]);
                RecoverSandbox::dispatch($move)->delay(now()->addMinutes(RecoverSandbox::CHECK_MINUTES));
                $this->tellAboutEarlierFiles($move);
            }
        }

        if ($move->source === MoveSource::Fresh && $move->snapshot?->task_id !== null) {
            $this->snapshots->forget($move->snapshot);
        }

        $this->suspendIfUnused($move, $sandbox->fresh());
        $move->update(['phase' => SandboxMovePhase::Done, 'work' => null, 'finished_at' => now()]);
        $move->note(__('Moved to the new sandbox.'));

        return true;
    }

    /**
     * Delete the sandbox the project moved off. A provider that fails to is left to clean up after itself.
     */
    protected function removeOld(SandboxMove $move, OldSandboxStatus $status = OldSandboxStatus::Removed): void
    {
        try {
            $this->provider->destroy((string) $move->from_external_id);
        } catch (SandboxException $e) {
            report($e);
        }

        $move->update(['old_status' => $status]);
    }

    /**
     * The chat says the project came back from earlier files, and a turn the old sandbox never finished is over.
     */
    protected function tellAboutEarlierFiles(SandboxMove $move): void
    {
        $sandbox = $move->sandbox;
        $conversation = $sandbox->task ?? $move->project;
        $from = $move->source === MoveSource::Snapshot && $move->snapshot
            ? __('the snapshot from :time', ['time' => $move->snapshot->created_at->toDayDateTimeString().' UTC'])
            : __('its code backup (its other files couldn\'t be kept)');

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => __(':old stopped answering, so this project moved to a new sandbox on :new with :from. Anything changed after that is still in the old sandbox, which is kept and checked on: if it answers again, its newer files are saved and an admin can restore them.', [
                'old' => $this->label($move->from_provider),
                'new' => $this->label($move->to_provider),
                'from' => $from,
            ]),
        ]);

        if ($conversation->status === ProjectStatus::Working) {
            app(AgentQueue::class)->finished($conversation);
        }
    }

    /**
     * Let the new sandbox stop using compute when nobody's using the project (memory kept, woken by the next visit),
     * as the update it replaces would have been; or always, when asked (sandbox:update).
     */
    protected function suspendIfUnused(SandboxMove $move, Sandbox $sandbox): void
    {
        $unused = ($sandbox->last_active_at ?? $sandbox->updated_at)->lt(now()->subMinutes(2)) && ! $move->project->busyIn($sandbox);

        if ($move->reason === 'computer' || ! (($move->options['suspend'] ?? false) || $unused)) {
            return;
        }

        try {
            $this->provider->suspend((string) $sandbox->external_id);
            // Stopped later like any suspended one (SBX-007).
            $sandbox->forceFill(['suspended_at' => now(), 'stopped_at' => null])->saveQuietly();
        } catch (SandboxException $e) {
            // It pauses by itself once idle; this only makes it sooner.
            report($e);
        }
    }

    /**
     * Stop the old sandbox (so files a database is writing are at rest), copy the kept paths out of it, and into the
     * new one, through the platform: for snapshot disks a sandbox can't reach (a local disk and a remote provider).
     *
     * @throws SandboxException
     */
    protected function copyThroughPlatform(SandboxMove $move): void
    {
        $directory = storage_path('framework/sandbox-backup-'.uniqid());
        $this->provider->pause((string) $move->from_external_id);
        $move->update(['options' => [...($move->options ?? []), 'frozen' => true]]);

        try {
            foreach (SandboxUpdater::KEPT_PATHS as $index => $path) {
                File::ensureDirectoryExists("{$directory}/{$index}");
                $this->provider->copyOut((string) $move->from_external_id, $path, "{$directory}/{$index}");
            }

            foreach (SandboxUpdater::KEPT_PATHS as $index => $path) {
                $this->provider->copyIn((string) $move->to_external_id, "{$directory}/{$index}", $path);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Hand the carried-over home folder back to the image's shell setup, and start the app that came with the files.
     *
     * @throws SandboxException
     */
    protected function startApp(SandboxMove $move): void
    {
        $this->provider->exec((string) $move->to_external_id, ['bash', '-c', SandboxUpdater::USE_IMAGE_SHELL_SETUP]);
        $this->provider->exec((string) $move->to_external_id, ['/opt/onedrop/restart']);
    }

    /**
     * The new sandbox, before the project's record points at it.
     */
    protected function newSandbox(SandboxMove $move): Sandbox
    {
        return $move->sandbox->replicate()->forceFill([
            'provider' => $move->to_provider,
            'external_id' => $move->to_external_id,
            'status' => SandboxStatus::Running,
        ])->setRelation('project', $move->project);
    }

    /**
     * The sandbox the project moved off, after its record points at the new one.
     */
    protected function oldSandbox(SandboxMove $move): Sandbox
    {
        return $move->sandbox->replicate()->forceFill([
            'provider' => $move->from_provider,
            'external_id' => $move->from_external_id,
            'status' => SandboxStatus::Running,
        ])->setRelation('project', $move->project);
    }

    protected function label(?string $provider): string
    {
        return SandboxProviders::PROVIDERS[$provider]['label'] ?? match ($provider) {
            'device' => __('your computer'),
            default => (string) $provider,
        };
    }
}
