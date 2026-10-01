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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ImportRepository implements ShouldQueue
{
    use Queueable;

    /** Fetching a large history takes a while. */
    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public Message $message, public string $branch) {}

    /**
     * Bring the repository's branch into the new project's empty sandbox (PRJ-009) before the agent's first run.
     * If it can't, the chain stops there: the agent never starts on an empty project.
     */
    public function handle(GitRemote $remote): void
    {
        try {
            $remote->pull($this->project->fresh() ?? $this->project, $this->branch);
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
