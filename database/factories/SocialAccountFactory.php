<?php

namespace Database\Factories;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
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
            'provider' => SocialProvider::GitHub,
            'provider_id' => (string) fake()->unique()->randomNumber(8),
            'email' => fake()->safeEmail(),
        ];
    }

    /**
     * A GitHub connection whose token can download the person's private packages (GIT-016).
     */
    public function withPackages(string $token = 'gho_packages'): static
    {
        return $this->state(fn () => ['provider' => SocialProvider::GitHub, 'token' => $token, 'scopes' => 'read:packages,user:email']);
    }
}
