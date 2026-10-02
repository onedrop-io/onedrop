<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectSnapshot>
 */
class ProjectSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $layers = collect(['workspace', 'deps', 'home', 'storage'])->mapWithKeys(fn (string $layer) => [$layer => [
            'path' => "project-snapshots/1/layers/{$layer}-".fake()->md5().'.tar.zst',
            'fingerprint' => fake()->md5(),
            'compression' => 'zst',
            'size' => 100,
        ]])->all();

        return [
            'project_id' => Project::factory(),
            'reason' => 'turn',
            'layers' => $layers,
            'size' => 400,
        ];
    }
}
