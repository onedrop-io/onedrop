<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The safety check for an agent run whose end never arrives (AGT-001): the forwarder posts it when the agent exits,
 * but a forwarder that died with its sandbox (a restart, a provider losing it) never does, and the chat stayed
 * "working" for good, its sidebar polling and its sandbox kept awake. Checked 15 minutes after the run starts, and
 * every 15 minutes while its forwarder is still running; a run whose forwarder is gone is ended.
 */
class WatchAgentRun implements ShouldQueue
{
    use Queueable;

    /** How long between checks. */
    public const MINUTES = 15;

    /** Checks in a row the sandbox may fail to answer before the run is given up on. */
    public const UNANSWERED = 4;

    public function __construct(public Project $project, public Message $message, public int $unanswered = 0) {}

    /**
     * Check on the run later. Not on a sync queue, which can't wait: checking straight away would end a run whose
     * forwarder hasn't started yet.
     */
    public static function later(Project $project, Message $message, int $unanswered = 0): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
            self::dispatch($project, $message, $unanswered)->delay(now()->addMinutes(self::MINUTES));
        }
    }

    public function handle(SandboxProvider $provider, AgentQueue $queue): void
    {
        $conversation = $this->message->conversation();

        // Over, or a later message's run, which has its own check.
        if ($conversation->getAttribute('status') !== ProjectStatus::Working
            || $conversation->messages()->where('role', MessageRole::User)->max('id') !== $this->message->id) {
            return;
        }

        $sandbox = $conversation->agentSandbox();

        if ($sandbox?->external_id !== null) {
            $run = $conversation->runKey();
            $pidFile = $run === 'main' ? '/tmp/onedrop-agent.pid' : '/tmp/onedrop-agent-'.preg_replace('/[^a-z0-9-]/', '', $run).'.pid';

            try {
                $alive = $provider->exec($sandbox->external_id, ['bash', '-c', 'kill -0 "$(cat "$1" 2>/dev/null)" 2>/dev/null', 'watch-agent', $pidFile])->successful();
            } catch (SandboxException) {
                if ($this->unanswered + 1 < self::UNANSWERED) {
                    self::later($this->project, $this->message, $this->unanswered + 1);

                    return;
                }

                $alive = false;
            }

            if ($alive) {
                self::later($this->project, $this->message);

                return;
            }
        }

        $conversation->messages()->create(['role' => MessageRole::Activity, 'content' => 'The agent stopped without finishing its turn']);
        $queue->finished($conversation);
    }
}
