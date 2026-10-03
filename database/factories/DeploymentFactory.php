<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
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
            'number' => 1,
            'status' => DeploymentStatus::Running,
            'step' => 'inspect',
        ];
    }

    /**
     * A deployment that went live with an image.
     */
    public function live(): static
    {
        return $this->state(fn () => [
            'kind' => 'server',
            'status' => DeploymentStatus::Live,
            'step' => 'done',
            'image' => 'registry.fly.io/onedrop-1:deployment-1',
            'url' => 'https://onedrop-1.fly.dev',
            'finished_at' => now(),
        ]);
    }
}
