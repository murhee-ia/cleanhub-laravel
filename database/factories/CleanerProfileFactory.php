<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\CleanerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CleanerProfile>
 */
class CleanerProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Cleaner]),
            'photo_path' => null,
            'bio' => fake()->sentence(),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'languages' => fake()->randomElements(['english', 'spanish', 'french', 'german'], 2),
            'documents' => [],
        ];
    }
}
