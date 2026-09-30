<?php

namespace App\Sandbox\Agents;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Jobs\BackupProject;
use App\Jobs\ForkTaskSandbox;
use App\Jobs\RunAgentTask;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Task;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * One agent run at a time per conversation (a project's main chat or one of its tasks; the conversations
 * themselves run in parallel). Messages sent while it works wait in a queue and start automatically,
 * in order, when the run finishes.
 */
class AgentQueue
{
    public function __construct(protected AgentRunner $runner) {}

    /**
     * Send a user message: run it now if the agent is free, otherwise queue it.
     * With $now, stop the current run and start this message instead (the queue stays).
     *
     * @param  list<UploadedFile>  $attachments
     */
    public function send(Conversation $conversation, string $content, bool $now = false, array $attachments = []): Message
    {
        if ($conversation->getAttribute('status') === ProjectStatus::Working) {
            if (! $now) {
                $message = $conversation->queuedMessages()->create([
                    'role' => MessageRole::User,
                    'content' => $content,
                    'queued' => true,
                ]);

                foreach ($attachments as $file) {
                    Attachment::store($message, $file);
                }

                return $message;
            }

            $this->interrupt($conversation);
        }

        return $this->run($conversation, $content, function (Message $message) use ($attachments) {
            foreach ($attachments as $file) {
                Attachment::store($message, $file);
            }
        });
    }

    /**
     * The current run ended (finished, failed, or couldn't start): go idle, then start the next queued message.
     * A task whose turn $succeeded goes to Review on the board (TASK-002); a failed one stays In progress.
     */
    public function finished(Conversation $conversation, bool $succeeded = false): void
    {
        if ($succeeded && $conversation instanceof Task && $conversation->stage === TaskStage::InProgress) {
            $conversation->update(['stage' => TaskStage::Review, 'position' => $conversation->project->nextTaskPosition(TaskStage::Review)]);
        }

        [$next, $attachments] = DB::transaction(function () use ($conversation) {
            $conversation->update(['status' => ProjectStatus::Idle]);

            $next = $conversation->queuedMessages()->lockForUpdate()->first();
            $attachments = $next?->attachments()->get() ?? collect();

            // The attachments move to the message that runs, so detach them before deleting this one.
            $next?->attachments()->update(['message_id' => null]);
            $next?->delete();

            return [$next, $attachments];
        });

        if ($next) {
            $this->run($conversation, $next->content, fn (Message $message) => $message->attachments()->saveMany($attachments));
        }
    }

    /**
     * Stop the current run and cancel the queue, returning the queued texts so the user can edit them.
     *
     * @return list<string>
     */
    public function stop(Conversation $conversation): array
    {
        $this->interrupt($conversation);

        $queued = $conversation->queuedMessages()->get();
        // One by one, so their attachments' files go too.
        $queued->each->delete();

        return array_values($queued->map(fn (Message $message) => $message->content)->all());
    }

    /**
     * Run the message whose Claude Code run failed because Claude wasn't signed in, now that the user has
     * signed in (AI-005), with an $activity line in the chat. False when there's none, or the agent is busy.
     */
    public function resumeAfterSignIn(Conversation $conversation, ?string $activity = 'Signed in to Claude, picking up where it left off'): bool
    {
        $messageId = $conversation->getAttribute('sign_in_retry_message_id');

        if ($messageId === null || $conversation->getAttribute('status') === ProjectStatus::Working) {
            return false;
        }

        $conversation->update(['sign_in_retry_message_id' => null]);
        $message = $conversation->messages()->where('role', MessageRole::User)->whereKey($messageId)->first();

        if (! $message) {
            return false;
        }

        $conversation->update(['status' => ProjectStatus::Working]);
        if ($activity !== null) {
            $conversation->messages()->create(['role' => MessageRole::Activity, 'content' => $activity]);
        }

        $this->start($conversation, $message);

        return true;
    }

    /**
     * End the current run without touching the queue.
     */
    protected function interrupt(Conversation $conversation): void
    {
        if ($conversation->getAttribute('status') !== ProjectStatus::Working) {
            return;
        }

        $this->runner->stop($conversation);
        // The forwarder commits the stopped turn's changes as it exits.
        BackupProject::dispatch($conversation->ownerProject())->delay(now()->addSeconds(15));

        $conversation->messages()->create(['role' => MessageRole::Activity, 'content' => 'Stopped']);
        $conversation->update(['status' => ProjectStatus::Idle]);
    }

    /**
     * Add the message to the chat, attach its files ($attach), and start the agent on it.
     *
     * @param  (Closure(Message): mixed)|null  $attach
     */
    protected function run(Conversation $conversation, string $content, ?Closure $attach = null): Message
    {
        // A new message replaces one waiting to run again after a Claude sign-in (AI-005).
        $conversation->update(['status' => ProjectStatus::Working, 'sign_in_retry_message_id' => null]);

        $message = $conversation->messages()->create(['role' => MessageRole::User, 'content' => $content]);

        if ($attach) {
            $attach($message);
        }

        $this->start($conversation, $message);

        return $message;
    }

    /**
     * Start the agent on a message already in the chat (the conversation is marked working).
     */
    protected function start(Conversation $conversation, Message $message): void
    {
        // A card starting (or going again) moves to In progress on the board (TASK-002).
        if ($conversation instanceof Task && $conversation->stage !== TaskStage::InProgress) {
            $conversation->update(['stage' => TaskStage::InProgress, 'position' => $conversation->project->nextTaskPosition(TaskStage::InProgress)]);
        }

        // A task's first run (or its first since it was applied) makes its own copy of the app first (TASK-003).
        if ($conversation instanceof Task && $conversation->needsCopy()) {
            $conversation->sandbox()->updateOrCreate([], ['provider' => config('sandbox.provider'), 'status' => SandboxStatus::Creating, 'external_id' => null, 'error' => null]);

            Bus::chain([
                new ForkTaskSandbox($conversation),
                new RunAgentTask($conversation->ownerProject(), $message),
            ])->dispatch();

            return;
        }

        RunAgentTask::dispatch($conversation->ownerProject(), $message);
    }
}
