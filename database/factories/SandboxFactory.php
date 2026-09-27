<?php

namespace Database\Factories;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sandbox>
 */
class SandboxFactory extends Factory
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
            'provider' => 'fake',
            'external_id' => fake()->uuid(),
            'status' => SandboxStatus::Running,
            'preview_url' => 'http://sandbox.test',
            'error' => null,
        ];
    }
}
