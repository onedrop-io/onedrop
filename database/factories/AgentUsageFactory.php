<?php

namespace Database\Factories;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Models\AgentUsage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentUsage>
 */
class AgentUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => fn (array $attributes) => Project::factory()->create(['user_id' => $attributes['user_id']])->id,
            'organization_id' => fn (array $attributes) => Project::query()->whereKey($attributes['project_id'])->value('organization_id'),
            'harness' => AgentHarness::ClaudeCode,
            'provider' => AgentProvider::Claude,
            'model' => 'claude-sonnet-5',
            'session_id' => fake()->uuid(),
            'input_tokens' => fake()->numberBetween(10, 5_000),
            'output_tokens' => fake()->numberBetween(100, 20_000),
            'cache_read_tokens' => fake()->numberBetween(10_000, 500_000),
            'cache_write_tokens' => fake()->numberBetween(1_000, 50_000),
            'cost' => fake()->randomFloat(4, 0.01, 5),
        ];
    }

    /**
     * An OpenCode step on an OpenAI model.
     */
    public function openCode(): static
    {
        return $this->state(fn () => [
            'harness' => AgentHarness::OpenCode,
            'provider' => AgentProvider::Codex,
            'model' => 'gpt-5.5',
        ]);
    }
}
