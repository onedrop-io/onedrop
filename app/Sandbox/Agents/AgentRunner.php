<?php

namespace App\Sandbox\Agents;

use App\Models\Message;
use App\Models\Project;

/**
 * Starts a coding-agent task for a project. Implementations must return quickly:
 * the agent reports progress later as events (see README "the one rule").
 */
interface AgentRunner
{
    /**
     * Start working on the given user message, in its conversation (the main chat or its task).
     */
    public function start(Project $project, Message $message): void;

    /**
     * Stop the conversation's current run, if any, leaving the project's other runs alone.
     * Anything the stopped run reports afterwards must be ignored.
     */
    public function stop(Conversation $conversation): void;
}
