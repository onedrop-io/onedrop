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
     * Work out what kind of credential was pasted.
     */
    public function credentialTypeFor(string $credential): CredentialType
    {
        return $this === self::Claude && str_starts_with($credential, 'sk-ant-oat')
            ? CredentialType::OAuthToken
            : CredentialType::ApiKey;
    }
}
