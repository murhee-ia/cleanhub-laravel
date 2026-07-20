<?php

namespace Database\Seeders;

use App\Models\CleaningJobCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CleaningJobCategorySeeder extends Seeder
{
    /**
     * Seed the initial admin-managed cleaning categories. Idempotent (keyed on
     * slug) so it can run repeatedly without duplicating rows.
     */
    public function run(): void
    {
        $names = [
            'Residential',
            'Hotel',
            'Hospital',
            'Office',
            'Factory',
            'Public Space',
            'Research Facility',
            'Event Cleanup',
        ];

        foreach ($names as $name) {
            CleaningJobCategory::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
