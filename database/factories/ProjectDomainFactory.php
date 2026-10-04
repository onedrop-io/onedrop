<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Project;
use App\Models\ProjectDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectDomain>
 */
class ProjectDomainFactory extends Factory
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
            'hostname' => fake()->unique()->domainWord().'.example.com',
            'primary' => false,
            'status' => DomainStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => DomainStatus::Active, 'verified_at' => now()]);
    }

    public function primary(): static
    {
        return $this->state(fn () => ['primary' => true]);
    }
}
