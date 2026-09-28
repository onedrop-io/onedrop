<?php

namespace Database\Factories;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentConnection>
 */
class AgentConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credential = 'sk-ant-api03-'.fake()->regexify('[A-Za-z0-9]{32}');

        return [
            'user_id' => User::factory(),
            'provider' => AgentProvider::Claude,
            'credential_type' => CredentialType::ApiKey,
            'credential' => $credential,
            'hint' => AgentConnection::hintFor($credential),
            'is_default' => true,
            'verified_at' => now(),
        ];
    }

    /**
     * Use the given provider.
     */
    public function provider(AgentProvider $provider): static
    {
        return $this->state(fn (array $attributes) => ['provider' => $provider]);
    }

    /**
     * A Codex connection from signing in with ChatGPT.
     *
     * @param  array<string, mixed>  $tokens
     */
    public function chatGpt(array $tokens = []): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => AgentProvider::Codex,
            'credential_type' => CredentialType::ChatGpt,
            'credential' => json_encode([
                'access' => 'chatgpt-access-token',
                'refresh' => 'chatgpt-refresh-token',
                'expires' => now()->addDays(10)->getTimestamp(),
                'account_id' => 'acct-1234',
                'email' => 'dev@example.com',
                ...$tokens,
            ]),
            'hint' => '1234',
        ]);
    }
}
