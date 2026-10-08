<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationSecret>
 */
class OrganizationSecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => Str::upper(fake()->unique()->lexify('SECRET_??????')),
            'value' => fake()->password(20),
            'all_projects' => true,
        ];
    }

    /**
     * Only for the projects it's attached to.
     */
    public function selected(): static
    {
        return $this->state(['all_projects' => false]);
    }
}
