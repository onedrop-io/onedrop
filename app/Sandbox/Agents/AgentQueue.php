<?php

namespace App\Sandbox\Agents;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * One agent run at a time per project. Messages sent while it works wait in a queue
 * and start automatically, in order, when the run finishes.
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
    public function send(Project $project, string $content, bool $now = false, array $attachments = []): Message
    {
        if ($project->status === ProjectStatus::Working) {
            if (! $now) {
                $message = $project->queuedMessages()->create([
                    'role' => MessageRole::User,
                    'content' => $content,
                    'queued' => true,
                ]);

                foreach ($attachments as $file) {
                    Attachment::store($message, $file);
                }

                return $message;
            }

            $this->interrupt($project);
        }

        return $this->run($project, $content, function (Message $message) use ($attachments) {
            foreach ($attachments as $file) {
                Attachment::store($message, $file);
            }
        });
    }

    /**
     * The current run ended (finished, failed, or couldn't start): go idle, then start the next queued message.
     */
    public function finished(Project $project): void
    {
        [$next, $attachments] = DB::transaction(function () use ($project) {
            $project->update(['status' => ProjectStatus::Idle]);

            $next = $project->queuedMessages()->lockForUpdate()->first();
            $attachments = $next?->attachments()->get() ?? collect();

            // The attachments move to the message that runs, so detach them before deleting this one.
            $next?->attachments()->update(['message_id' => null]);
            $next?->delete();

            return [$next, $attachments];
        });

        if ($next) {
            $this->run($project, $next->content, fn (Message $message) => $message->attachments()->saveMany($attachments));
        }
    }

    /**
     * Stop the current run and cancel the queue, returning the queued texts so the user can edit them.
     *
     * @return list<string>
     */
    public function stop(Project $project): array
    {
        $this->interrupt($project);

        $queued = $project->queuedMessages()->get();
        // One by one, so their attachments' files go too.
        $queued->each->delete();

        return $queued->pluck('content')->all();
    }

    /**
     * End the current run without touching the queue.
     */
    protected function interrupt(Project $project): void
    {
        if ($project->status !== ProjectStatus::Working) {
            return;
        }

        $this->runner->stop($project);

        $project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Stopped']);
        $project->update(['status' => ProjectStatus::Idle]);
    }

    /**
     * Add the message to the chat, attach its files ($attach), and start the agent on it.
     *
     * @param  (Closure(Message): mixed)|null  $attach
     */
    protected function run(Project $project, string $content, ?Closure $attach = null): Message
    {
        $project->update(['status' => ProjectStatus::Working]);

        $message = $project->messages()->create(['role' => MessageRole::User, 'content' => $content]);

        if ($attach) {
            $attach($message);
        }

        RunAgentTask::dispatch($project, $message);

        return $message;
    }
}
