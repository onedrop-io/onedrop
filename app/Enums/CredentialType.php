<?php

namespace App\Enums;

enum CredentialType: string
{
    case ApiKey = 'api_key';

    /**
     * The user's Claude subscription, signed in inside their sandboxes through Claude Code's own
     * `claude auth login`. Nothing is stored: Anthropic requires its own sign-in flow and forbids
     * apps from collecting Claude tokens.
     */
    case ClaudeLogin = 'claude_login';

    /** Tokens from signing in with ChatGPT (a JSON bundle), refreshed by the platform. */
    case ChatGpt = 'chatgpt';

    /** The user's own Ollama server: its URL and an optional key (a JSON bundle, AI-006). */
    case OllamaServer = 'ollama_server';
}
