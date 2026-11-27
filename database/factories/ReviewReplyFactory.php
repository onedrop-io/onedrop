<?php

namespace Database\Factories;

use App\Models\ReviewReply;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewReply>
 */
class ReviewReplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'thread_id' => 'PRRT_'.fake()->bothify('??##??##'),
            'path' => 'app/Login.php',
            'line' => 14,
            'outcome' => 'fixed',
            'body' => 'Fixed the typo.',
        ];
    }

    /**
     * A reply that declines the comment.
     */
    public function declined(): static
    {
        return $this->state(['outcome' => 'declined', 'body' => 'This is intended: the old name is still used by the API.']);
    }
}
