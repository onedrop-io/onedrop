<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Enums\TaskStage;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'title' => fake()->sentence(4),
            'stage' => TaskStage::Todo,
        ];
    }

    /**
     * A task whose agent is running.
     */
    public function working(): static
    {
        return $this->state(['stage' => TaskStage::InProgress, 'status' => ProjectStatus::Working]);
    }
}
