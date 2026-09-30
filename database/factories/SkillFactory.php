<?php

namespace Database\Factories;

use App\Enums\SkillSource;
use App\Models\Skill;
use App\Models\User;
use App\Sandbox\SkillDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2, false);
        $description = fake()->sentence();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'description' => $description,
            'content' => SkillDocument::compose($name, $description, fake()->paragraph()),
            'files' => [],
            'shared' => false,
            'source' => SkillSource::Written,
        ];
    }

    /**
     * Everyone on the server can see it.
     */
    public function shared(): static
    {
        return $this->state(fn () => ['shared' => true]);
    }
}
