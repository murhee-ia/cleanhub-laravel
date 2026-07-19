<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Provision the single admin account. Keyed on the admin role so it stays
     * idempotent and never creates a second admin.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['role' => UserRole::Admin],
            [
                'name' => config('cleanhub.admin.name'),
                'email' => config('cleanhub.admin.email'),
                'password' => config('cleanhub.admin.password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
