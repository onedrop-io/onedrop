<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'role' => MessageRole::User,
            'content' => fake()->sentence(),
        ];
    }
}
