<?php

namespace App\Jobs;

use App\Enums\GitSyncStatus;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\ProjectSnapshots;
use App\Sandbox\SandboxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ImportRepository implements ShouldQueue
{
    use Queueable;

    /** Long enough for a large repository's fetch on the platform (GitRemote::FETCH_TIMEOUT) and copying it in. */
    public int $timeout = 1500;

    /** The safety checks on a fetch the sandbox runs itself: after 15 minutes, and the last after 30. */
    public const LAST_CHECK = 2;

    /** Queued by the sandbox's own call that the fetch is over, not by a safety check. */
    public const CALLED_BACK = 3;

    public const CHECK_MINUTES = 15;

    public int $tries = 1;

    /**
     * @param  int  $check  0 for the import itself, 1..LAST_CHECK for a safety check, CALLED_BACK when the sandbox said it's done
     */
    public function __construct(public Project $project, public Message $message, public string $branch, public int $check = 0) {}

    /**
     * Bring the repository's branch into the new project's empty sandbox (PRJ-009) before the agent's first run.
     * If it can't, the chain stops there: the agent never starts on an empty project.
     */
    public function handle(GitRemote $remote): void
    {
        $project = $this->project->fresh() ?? $this->project;

        // A safety check or the sandbox's call, after the import was already brought in (or failed).
        if ($this->check > 0 && $project->git_sync_status !== GitSyncStatus::Pulling) {
            return;
        }

        try {
            $done = $remote->pullStep($project, $this->branch, ProjectSnapshots::callbackUrl('sandbox-events.fetched', [
                'project' => $project, 'job' => 'import', 'message' => $this->message->id, 'branch' => $this->branch,
            ]));
        } catch (GitException|SandboxException $e) {
            $this->fail($e);

            return;
        }

        if (! $done) {
            $this->waitForTheSandbox();

            return;
        }

        // Carry on with the rest of the chain (the agent's first run), set aside while the sandbox fetched.
        $meta = $this->message->fresh()->meta ?? [];

        if (isset($meta['import_chain'])) {
            $this->chained = $meta['import_chain'];
            unset($meta['import_chain']);
            $this->message->update(['meta' => $meta]);
        }

        $project->update(['git_sync_status' => null, 'git_sync_error' => null, 'git_synced_at' => now()]);
    }

    /**
     * The sandbox fetches in the background and says when it's done (SandboxWorkController queues this again). Until
     * then the rest of the chain waits with the message, so the agent never starts on an empty project; a safety
     * check after 15 and 30 minutes catches a call that never arrives.
     */
    protected function waitForTheSandbox(): void
    {
        if ($this->chained !== []) {
            $this->message->update(['meta' => [...($this->message->fresh()->meta ?? []), 'import_chain' => $this->chained]]);
            $this->chained = [];
        }

        if ($this->check < self::LAST_CHECK) {
            self::dispatch($this->project, $this->message, $this->branch, $this->check + 1)->delay(now()->addMinutes(self::CHECK_MINUTES));
        } elseif ($this->check === self::LAST_CHECK) {
            $this->fail(new GitException(__('The download didn\'t finish in time. Try again.')));
        }
    }

    /**
     * Say why in the chat, and leave the project idle so the user can try something else.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception instanceof GitException || $exception instanceof SandboxException
            ? Str::limit($exception->getMessage(), 500)
            : __('Something went wrong. Try again.');

        $this->project->update(['git_sync_status' => GitSyncStatus::Failed, 'git_sync_error' => $reason]);

        $conversation = $this->message->conversation();
        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => __('Couldn\'t import the repository: :reason', ['reason' => $reason]),
        ]);
        app(AgentQueue::class)->finished($conversation);
    }
}
