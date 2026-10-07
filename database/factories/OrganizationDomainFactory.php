<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationDomain>
 */
class OrganizationDomainFactory extends Factory
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
            'domain' => fake()->unique()->domainName(),
        ];
    }

    /**
     * A domain whose TXT record was found.
     */
    public function verified(): static
    {
        return $this->state(['verified_at' => now()]);
    }
}
