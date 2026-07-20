<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployerProfile>
 */
class EmployerProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Employer]),
            'employer_type' => fake()->randomElement(['individual', 'company', 'agency', 'property_owner', 'facility_manager', 'other']),
            'contact_person_name' => fake()->name(),
            'contact_person_contact' => fake()->email(),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'address' => fake()->streetAddress(),
            'about' => fake()->paragraph(),
            'photo_path' => null,
            'documents' => [],
        ];
    }
}
