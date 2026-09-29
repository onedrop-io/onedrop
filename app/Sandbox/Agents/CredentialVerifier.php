<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Checks a pasted credential against the provider before we store it.
 */
class CredentialVerifier
{
    /**
     * Verify the credential, returning false when it can't be checked
     * (Claude subscription tokens have no public endpoint to test against).
     *
     * @throws ValidationException when the provider rejects the credential or can't be reached
     */
    public function verify(AgentProvider $provider, CredentialType $type, string $credential): bool
    {
        if ($type === CredentialType::OAuthToken) {
            return false;
        }

        $request = match ($provider) {
            AgentProvider::Claude => fn () => Http::withHeaders([
                'x-api-key' => $credential,
                'anthropic-version' => '2023-06-01',
            ])->get('https://api.anthropic.com/v1/models'),
            AgentProvider::Codex => fn () => Http::withToken($credential)->get('https://api.openai.com/v1/models'),
            AgentProvider::OpenRouter => fn () => Http::withToken($credential)->get('https://openrouter.ai/api/v1/key'),
            AgentProvider::Gemini => fn () => Http::withHeaders([
                'x-goog-api-key' => $credential,
            ])->get('https://generativelanguage.googleapis.com/v1beta/models'),
        };

        try {
            $response = $request();
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'credential' => __("Couldn't reach :provider to check the key. Try again.", ['provider' => $provider->label()]),
            ]);
        }

        // Google answers 400 ("API key not valid") rather than 401 for a bad key.
        if ($response->unauthorized() || $response->forbidden() || ($provider === AgentProvider::Gemini && $response->badRequest())) {
            throw ValidationException::withMessages([
                'credential' => __(':provider rejected that key.', ['provider' => $provider->label()]),
            ]);
        }

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'credential' => __(':provider returned an error while checking the key. Try again.', ['provider' => $provider->label()]),
            ]);
        }

        return true;
    }
}
