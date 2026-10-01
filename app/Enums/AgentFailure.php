<?php

namespace App\Enums;

/**
 * Why an agent run failed, as Jev judges it from the error when none of the known error texts matched (AGT-010).
 * Each harness's events class says what to tell the user for each (AgentEvents::failureMessage()).
 */
enum AgentFailure: string
{
    case BadKey = 'bad_key';
    case OutOfCredits = 'out_of_credits';
    case RateLimited = 'rate_limited';
    case ModelUnavailable = 'model_unavailable';
    case ContextTooLong = 'context_too_long';
    case Network = 'network';
    case Other = 'other';

    /**
     * What each cause looks like, for Jev to choose from.
     *
     * @return array<string, string>
     */
    public static function criteria(): array
    {
        return [
            self::BadKey->value => 'The AI provider turned down the API key or sign-in: unauthorized, forbidden, invalid, expired or revoked credentials (401, 403).',
            self::OutOfCredits->value => "The AI account has no credits or balance left, its quota or plan's usage limit is used up, or payment is required (402).",
            self::RateLimited->value => 'Too many requests, or the AI provider is overloaded or temporarily unavailable (429, 503, 529).',
            self::ModelUnavailable->value => "The chosen AI model doesn't exist, was retired, or isn't available to this account.",
            self::ContextTooLong->value => "The conversation or request is too long for the model's context window (too many tokens, prompt too long).",
            self::Network->value => "The AI provider couldn't be reached: a network or DNS error, a connection refused or reset, or a timeout.",
            self::Other->value => 'Anything else, such as a crash or bug in the agent, a tool or the sandbox, or too little to tell.',
        ];
    }
}
