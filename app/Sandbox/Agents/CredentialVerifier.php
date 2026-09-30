<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Checks a pasted API key against the provider before we store it.
 */
class CredentialVerifier
{
    /**
     * Verify the API key with the provider.
     *
     * @throws ValidationException when the provider rejects the key or can't be reached
     */
    public function verify(AgentProvider $provider, string $credential): void
    {
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
            // Ollama Cloud lists models without a key; who-am-I needs a good one.
            AgentProvider::Ollama => fn () => Http::withToken($credential)->post('https://ollama.com/api/me'),
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
    }
}
