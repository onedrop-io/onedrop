<?php

namespace Database\Factories;

use App\Models\GitHubAuthorization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubAuthorization>
 */
class GitHubAuthorizationFactory extends Factory
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
            'github_login' => fake()->userName(),
            'access_token' => 'ghu_'.fake()->sha1(),
            'refresh_token' => 'ghr_'.fake()->sha1(),
            'expires_at' => now()->addHours(8),
            'refresh_expires_at' => now()->addMonths(6),
        ];
    }

    /**
     * A token past its eight hours, still refreshable.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
