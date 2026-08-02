<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cleaning_job_post_id' => CleaningJobPost::factory(),
            'user_id' => User::factory()->state(['role' => UserRole::Cleaner]),
            'status' => ApplicationStatus::Pending,
            'message' => fake()->optional()->sentence(),
            'resume_path' => null,
            'private_note' => null,
        ];
    }

    public function status(ApplicationStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
