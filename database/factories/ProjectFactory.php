<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prompt = fake()->sentence();

        return [
            'user_id' => User::factory(),
            'name' => Project::nameFromPrompt($prompt),
            'prompt' => $prompt,
            'status' => ProjectStatus::Idle,
        ];
    }
}
