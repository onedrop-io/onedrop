<?php

namespace Database\Seeders;

use App\Enums\AgentProvider;
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

        if ($credential = config('sandbox.dev_claude_credential')) {
            $dev->agentConnections()->create([
                'provider' => AgentProvider::Claude,
                'credential_type' => AgentProvider::Claude->credentialTypeFor($credential),
                'credential' => $credential,
                'hint' => AgentConnection::hintFor($credential),
                'is_default' => true,
            ]);
        }
    }
}
