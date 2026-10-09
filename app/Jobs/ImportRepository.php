<?php

namespace App\Jobs;

use App\Enums\GitSyncStatus;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\GitException;
use App\Sandbox\GitRemote;
use App\Sandbox\SandboxException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ImportRepository implements ShouldQueue
{
    use Queueable;

    /** Long enough for a large repository's fetch on the platform (GitRemote::FETCH_TIMEOUT) and copying it in. */
    public int $timeout = 1500;

    /** A fetch the sandbox runs itself is checked on every few seconds, each check a short attempt. */
    public const CHECK_SECONDS = 5;

    /** Any error other than checking back ends it. */
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public Message $message, public string $branch) {}

    /**
     * Checking back on the sandbox's fetch makes many attempts; give up once a fetch couldn't still be running.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(GitRemote::FETCH_TIMEOUT + 600);
    }

    /**
     * Bring the repository's branch into the new project's empty sandbox (PRJ-009) before the agent's first run.
     * If it can't, the chain stops there: the agent never starts on an empty project.
     */
    public function handle(GitRemote $remote): void
    {
        try {
            if (! $remote->pullStep($this->project->fresh() ?? $this->project, $this->branch)) {
                $this->release(self::CHECK_SECONDS);

                return;
            }
        } catch (GitException|SandboxException $e) {
            $this->fail($e);

            return;
        }

        $this->project->update(['git_sync_status' => null, 'git_sync_error' => null, 'git_synced_at' => now()]);
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
