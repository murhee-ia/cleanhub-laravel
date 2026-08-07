<?php

namespace Database\Factories;

use App\Enums\RatingStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'reviewer_id' => User::factory()->state(['role' => UserRole::Employer]),
            'reviewee_id' => User::factory()->state(['role' => UserRole::Cleaner]),
            'stars' => fake()->numberBetween(1, 5),
            'text' => fake()->optional()->sentence(),
            'status' => RatingStatus::Visible,
        ];
    }

    public function status(RatingStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
