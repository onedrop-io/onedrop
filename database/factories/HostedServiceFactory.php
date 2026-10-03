<?php

namespace Database\Factories;

use App\Enums\HostedServiceKind;
use App\Models\HostedService;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostedService>
 */
class HostedServiceFactory extends Factory
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
            'kind' => HostedServiceKind::App,
            'provider' => 'fly',
            'owner' => HostedService::OWNER_PLATFORM,
            'name' => 'onedrop-'.fake()->unique()->numberBetween(1, 99999),
            'details' => [],
        ];
    }
}
