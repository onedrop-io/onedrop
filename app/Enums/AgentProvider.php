<?php

namespace App\Enums;

enum AgentProvider: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case OpenRouter = 'openrouter';
    case Gemini = 'gemini';

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
