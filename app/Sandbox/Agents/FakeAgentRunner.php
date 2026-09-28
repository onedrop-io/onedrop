<?php

namespace App\Sandbox\Agents;

use App\Enums\MessageRole;
use App\Jobs\AppendAgentEvent;
use App\Models\Message;
use App\Models\Project;

/**
 * Local stand-in until the Claude Code adapter exists (README M2). Emits a
 * scripted reply as delayed events so the chat streams like a real agent.
 */
class FakeAgentRunner implements AgentRunner
{
    /**
     * Seconds between scripted events.
     */
    public const STEP_SECONDS = 1;

    /**
     * Queue the scripted reply for the given user message.
     */
    public function start(Project $project, Message $message): void
    {
        $ai = $project->user->agentConnections()->firstWhere('is_default', true)?->provider->label() ?? 'your AI';

        $events = [
            [MessageRole::Activity, 'Planning app development'],
            [MessageRole::Assistant, "Got it. I'll work on: \"{$message->content}\" using {$ai}."],
            [MessageRole::Activity, 'Starting your project'],
            [MessageRole::Assistant, "I'm a **placeholder agent** for now, so nothing was built yet. Your sandbox is running on the right; once the Claude Code adapter lands, I'll build the app there."],
        ];

        foreach ($events as $step => [$role, $content]) {
            AppendAgentEvent::dispatch($project, $role, $content, finished: $step === array_key_last($events))
                ->delay(now()->addSeconds(($step + 1) * self::STEP_SECONDS));
        }
    }

    /**
     * Nothing runs outside the queue, so there's nothing to stop.
     */
    public function stop(Project $project): void {}
}
