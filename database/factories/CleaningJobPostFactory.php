<?php

namespace Database\Factories;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Enums\UserRole;
use App\Models\CleaningJobCategory;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CleaningJobPost>
 */
class CleaningJobPostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employer_id' => User::factory()->state(['role' => UserRole::Employer]),
            'cleaning_job_category_id' => CleaningJobCategory::factory(),
            'title' => fake()->randomElement(['Residential Deep Cleaning', 'Office Nightly Clean', 'Hotel Housekeeping', 'Post-Event Cleanup']).' Needed',
            'description' => fake()->paragraph(),
            'requirements' => fake()->optional()->sentence(),
            'qualifications' => fake()->optional()->sentence(),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'address' => fake()->optional()->streetAddress(),
            'schedule_date' => fake()->dateTimeBetween('+3 days', '+2 months')->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '13:00',
            'cleaners_needed' => fake()->numberBetween(1, 5),
            'application_deadline' => fake()->boolean(70) ? fake()->dateTimeBetween('now', '+2 days')->format('Y-m-d') : null,
            'visibility' => JobPostVisibility::Published,
            'status' => JobPostStatus::Open,
            'pay_amount' => fake()->randomFloat(2, 15, 200),
            'pay_currency' => fake()->randomElement(['USD', 'EUR', 'PHP']),
            'media' => [],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => JobPostVisibility::Draft]);
    }

    public function status(JobPostStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
