<?php

namespace App\Actions;

use App\Enums\AgentProvider;
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
        $type = $provider->credentialTypeFor($credential);
        $verified = $this->verifier->verify($provider, $type, $credential);
        $existing = $user->agentConnections()->firstWhere('provider', $provider);

        return $user->agentConnections()->updateOrCreate(
            ['provider' => $provider],
            [
                'credential_type' => $type,
                'credential' => $credential,
                'hint' => AgentConnection::hintFor($credential),
                'verified_at' => $verified ? now() : null,
                'is_default' => $existing ? $existing->is_default : ! $user->agentConnections()->exists(),
            ],
        );
    }
}
