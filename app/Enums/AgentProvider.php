<?php

namespace App\Enums;

enum AgentProvider: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case OpenRouter = 'openrouter';

    /**
     * Human-readable name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::Codex => 'Codex',
            self::OpenRouter => 'OpenRouter',
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
