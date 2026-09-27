<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(40);

        return [
            'email' => null,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
        ];
    }
}
