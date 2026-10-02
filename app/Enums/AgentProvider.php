<?php

namespace App\Enums;

enum AgentProvider: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case OpenRouter = 'openrouter';
    case Gemini = 'gemini';
    case Ollama = 'ollama';
    /** The platform's AI, paid from the organization's AI credits (CREDIT-001); never a user's connection. */
    case Credits = 'credits';

    /**
     * Human-readable name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::Codex => 'Codex',
            self::OpenRouter => 'OpenRouter',
            self::Gemini => 'Gemini',
            self::Ollama => 'Ollama',
            self::Credits => 'AI credits',
        };
    }

    /**
     * The provider's id in OpenCode and the models.dev catalog.
     */
    public function catalogId(): string
    {
        return match ($this) {
            self::Claude => 'anthropic',
            self::Codex => 'openai',
            self::OpenRouter => 'openrouter',
            self::Gemini => 'google',
            self::Ollama => 'ollama-cloud',
            self::Credits => 'openrouter',
        };
    }

    /**
     * Name shown in the model picker (the company, not the CLI).
     */
    public function pickerLabel(): string
    {
        return match ($this) {
            self::Claude => 'Anthropic',
            self::Codex => 'OpenAI',
            self::OpenRouter => 'OpenRouter',
            self::Gemini => 'Google',
            self::Ollama => 'Ollama',
            self::Credits => 'AI credits',
        };
    }

    /**
     * Whether a pasted credential is a Claude subscription token (from `claude setup-token`). OneDrop
     * doesn't take those: a subscription is signed in inside the sandbox instead (CredentialType::ClaudeLogin).
     */
    public function isSubscriptionToken(string $credential): bool
    {
        return $this === self::Claude && str_starts_with(trim($credential), 'sk-ant-oat');
    }
}
