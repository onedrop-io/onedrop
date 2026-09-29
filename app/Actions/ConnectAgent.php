<?php

namespace App\Actions;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\User;
use App\Sandbox\Agents\CredentialVerifier;
use Illuminate\Validation\ValidationException;

class ConnectAgent
{
    public function __construct(protected CredentialVerifier $verifier) {}

    /**
     * Verify and store (or replace) the user's credential for a provider.
     * The first connection becomes the default.
     *
     * @throws ValidationException
     */
    public function handle(User $user, AgentProvider $provider, string $credential): AgentConnection
    {
        $credential = trim($credential);

        if ($provider->isSubscriptionToken($credential)) {
            throw ValidationException::withMessages([
                'credential' => __('OneDrop doesn\'t take Claude subscription tokens. Choose "Use my Claude subscription" instead, then sign in to Claude from your project.'),
            ]);
        }

        $this->verifier->verify($provider, $credential);

        return $this->store($user, $provider, CredentialType::ApiKey, $credential, AgentConnection::hintFor($credential), verified: true);
    }

    /**
     * Use the user's Claude subscription with Claude Code. Nothing is stored: they sign in inside their
     * sandboxes through Claude Code's own `claude auth login`, as Anthropic requires.
     */
    public function claudeLogin(User $user): AgentConnection
    {
        return $this->store($user, AgentProvider::Claude, CredentialType::ClaudeLogin, '', '', verified: false);
    }

    /**
     * Store the tokens from signing in with ChatGPT as the user's Codex connection.
     * They were just issued by OpenAI, so there's nothing to verify.
     *
     * @param  array{access: string, refresh: string, expires: int, account_id: string|null, email: string|null}  $tokens
     */
    public function chatGpt(User $user, array $tokens): AgentConnection
    {
        return $this->store(
            $user,
            AgentProvider::Codex,
            CredentialType::ChatGpt,
            json_encode($tokens, JSON_THROW_ON_ERROR),
            AgentConnection::hintFor($tokens['account_id'] ?? $tokens['access']),
            verified: true,
        );
    }

    protected function store(User $user, AgentProvider $provider, CredentialType $type, string $credential, string $hint, bool $verified): AgentConnection
    {
        $existing = $user->agentConnections()->firstWhere('provider', $provider);

        return $user->agentConnections()->updateOrCreate(
            ['provider' => $provider],
            [
                'credential_type' => $type,
                'credential' => $credential,
                'hint' => $hint,
                'verified_at' => $verified ? now() : null,
                'is_default' => $existing ? $existing->is_default : ! $user->agentConnections()->exists(),
            ],
        );
    }
}
