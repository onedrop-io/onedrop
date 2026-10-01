<?php

namespace Database\Factories;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::install()->id,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Attach the given user as the group's owner.
     */
    public function ownedBy(User $user): static
    {
        return $this->hasAttached($user, ['role' => GroupRole::Owner->value], 'members');
    }
}
