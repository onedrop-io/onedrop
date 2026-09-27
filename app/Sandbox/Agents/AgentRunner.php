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
     * Start working on the given user message.
     */
    public function start(Project $project, Message $message): void;
}
