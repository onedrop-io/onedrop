<?php

namespace App\Enums;

/**
 * The coding agent that works in a project's sandbox.
 */
enum AgentHarness: string
{
    case OpenCode = 'opencode';
    case ClaudeCode = 'claude_code';
    case Codex = 'codex';

    /**
     * Human-readable name.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenCode => 'OpenCode',
            self::ClaudeCode => 'Claude Code',
            self::Codex => 'Codex',
        };
    }
}
