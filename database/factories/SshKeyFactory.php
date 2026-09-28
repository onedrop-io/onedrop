<?php

namespace Database\Factories;

use App\Models\SshKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    /**
     * Define the model's default state: a well-formed ed25519 key with random key bytes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32);
        $key = 'ssh-ed25519 '.base64_encode($blob);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'public_key' => $key,
            'fingerprint' => SshKey::parse($key)['fingerprint'],
        ];
    }
}
