<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubInstallation>
 */
class GitHubInstallationFactory extends Factory
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
            'installation_id' => fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'account_login' => fake()->userName(),
            'account_type' => 'User',
            'account_avatar_url' => null,
            'repository_selection' => 'selected',
        ];
    }
}
