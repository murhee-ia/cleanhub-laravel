<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CleaningJobCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dev-only demo data: a handful of fully-populated cleaner and employer
 * profiles so the frontend can develop against real seeded records instead of
 * mocks. Idempotent — keyed on email — so it can run repeatedly.
 */
class DemoProfileSeeder extends Seeder
{
    public function run(): void
    {
        $categoriesBySlug = CleaningJobCategory::pluck('id', 'slug');

        $cleaners = [
            [
                'name' => 'Jane Dela Cruz',
                'email' => 'cleaner1@demo.test',
                'profile' => [
                    'bio' => 'Detail-oriented residential and office cleaner with eight years of experience.',
                    'country' => 'Philippines',
                    'city' => 'Cebu City',
                    'languages' => ['english', 'tagalog', 'cebuano'],
                ],
                'categories' => ['residential', 'office'],
            ],
            [
                'name' => 'Marco Bianchi',
                'email' => 'cleaner2@demo.test',
                'profile' => [
                    'bio' => 'Hotel and hospital sanitation specialist, trained in medical-grade disinfection.',
                    'country' => 'Italy',
                    'city' => 'Milan',
                    'languages' => ['english', 'italian'],
                ],
                'categories' => ['hotel', 'hospital'],
            ],
            [
                'name' => 'Aisha Rahman',
                'email' => 'cleaner3@demo.test',
                'profile' => [
                    'bio' => 'Post-event and factory floor cleanup, comfortable with large teams and tight turnarounds.',
                    'country' => 'United Arab Emirates',
                    'city' => 'Dubai',
                    'languages' => ['english', 'arabic'],
                ],
                'categories' => ['event-cleanup', 'factory', 'public-space'],
            ],
        ];

        foreach ($cleaners as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'role' => UserRole::Cleaner,
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );

            $profile = $user->cleanerProfile()->firstOrCreate([], $data['profile']);

            $categoryIds = $categoriesBySlug
                ->only($data['categories'])
                ->values()
                ->all();

            $profile->cleaningJobCategories()->sync($categoryIds);
        }

        $employers = [
            [
                'name' => 'Sparkle Facilities Inc.',
                'email' => 'employer1@demo.test',
                'profile' => [
                    'employer_type' => 'company',
                    'contact_person_name' => 'Maria Reyes',
                    'contact_person_contact' => 'maria@sparkle.test',
                    'country' => 'Philippines',
                    'city' => 'Manila',
                    'address' => '123 Ayala Avenue, Makati',
                    'about' => 'Commercial cleaning contractor servicing offices and malls across Metro Manila.',
                ],
            ],
            [
                'name' => 'Green Hotel Group',
                'email' => 'employer2@demo.test',
                'profile' => [
                    'employer_type' => 'agency',
                    'contact_person_name' => 'Tom Becker',
                    'contact_person_contact' => '+49 151 2345678',
                    'country' => 'Germany',
                    'city' => 'Berlin',
                    'address' => null,
                    'about' => 'Boutique hotel chain hiring housekeeping staff for seasonal peaks.',
                ],
            ],
            [
                'name' => 'Robert Santos',
                'email' => 'employer3@demo.test',
                'profile' => [
                    'employer_type' => 'individual',
                    'contact_person_name' => 'Robert Santos',
                    'contact_person_contact' => 'robert.santos@demo.test',
                    'country' => 'Philippines',
                    'city' => 'Davao City',
                    'address' => null,
                    'about' => 'Homeowner looking for reliable recurring residential cleaning help.',
                ],
            ],
        ];

        foreach ($employers as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'role' => UserRole::Employer,
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );

            $user->employerProfile()->firstOrCreate([], $data['profile']);
        }
    }
}
