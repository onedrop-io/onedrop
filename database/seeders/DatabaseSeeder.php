<?php

namespace Database\Seeders;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\GroupRole;
use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with known local dev data.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Skipping dev seed data outside local/testing.');

            return;
        }

        $dev = User::factory()->admin()->create([
            'name' => 'Dev User',
            'email' => 'dev@example.com',
        ]);

        $member = User::factory()->create([
            'name' => 'Sam Member',
            'email' => 'sam@example.com',
        ]);

        Group::factory()
            ->ownedBy($dev)
            ->hasAttached($member, ['role' => GroupRole::Member->value], 'members')
            ->create(['name' => 'Engineering', 'description' => 'Platform builders.']);

        // A subscription token isn't stored (AI-005): the dev user signs in to Claude in the sandbox instead.
        if ($credential = config('sandbox.dev_claude_credential')) {
            $subscription = AgentProvider::Claude->isSubscriptionToken($credential);

            $dev->agentConnections()->create([
                'provider' => AgentProvider::Claude,
                'credential_type' => $subscription ? CredentialType::ClaudeLogin : CredentialType::ApiKey,
                'credential' => $subscription ? '' : $credential,
                'hint' => $subscription ? '' : AgentConnection::hintFor($credential),
                'is_default' => true,
            ]);
        }
    }
}
