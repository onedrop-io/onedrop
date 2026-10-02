<?php

namespace App\Enums;

/**
 * What paid for an agent run (USAGE-001), so the Usage page only counts money spent as cost.
 */
enum UsagePayer: string
{
    /** The organization's AI credits (CREDIT-001). */
    case Credits = 'credits';

    /** The user's Claude or ChatGPT subscription: no cost per run. */
    case Plan = 'plan';

    /** The user's own API key, billed by their provider. */
    case ApiKey = 'api_key';

    /** The user's own Ollama server: free. */
    case OwnServer = 'own_server';

    /**
     * Whether a run paid this way cost money per use.
     */
    public function costsMoney(): bool
    {
        return $this === self::Credits || $this === self::ApiKey;
    }

    /**
     * How a connection pays for what it runs.
     */
    public static function forCredential(CredentialType $type): self
    {
        return match ($type) {
            CredentialType::ClaudeLogin, CredentialType::ChatGpt => self::Plan,
            CredentialType::OllamaServer => self::OwnServer,
            CredentialType::ApiKey => self::ApiKey,
        };
    }
}
