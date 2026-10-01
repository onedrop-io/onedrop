<?php

namespace Database\Factories;

use App\Enums\AbuseReviewStatus;
use App\Models\AbuseReview;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbuseReview>
 */
class AbuseReviewFactory extends Factory
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
            'status' => AbuseReviewStatus::Clear,
            'trigger' => 'publish',
            'score' => 0.02,
            'reasons' => ['phishing' => 0.02, 'scam' => 0.01, 'harmful' => 0.01],
            'evidence' => ['project_name' => 'Todos', 'prompts' => ['a todo app'], 'page' => null],
            'checked_at' => now(),
        ];
    }

    /**
     * Flagged by Jev and waiting for a platform admin.
     */
    public function held(): static
    {
        return $this->state(fn () => [
            'status' => AbuseReviewStatus::Held,
            'score' => 0.97,
            'reasons' => ['phishing' => 0.97, 'scam' => 0.6, 'harmful' => 0.2],
            'evidence' => ['project_name' => 'Account verify', 'prompts' => ['copy the PayPal login'], 'page' => ['title' => 'PayPal: Log in', 'text' => 'Log in to PayPal', 'fields' => ['email login_email', 'password login_password']]],
            'flagged_at' => now(),
        ]);
    }
}
