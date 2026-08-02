<?php

namespace Database\Factories;

use App\Models\CleaningJobPost;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedJob>
 */
class SavedJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->cleaner(),
            'cleaning_job_post_id' => CleaningJobPost::factory(),
        ];
    }
}
