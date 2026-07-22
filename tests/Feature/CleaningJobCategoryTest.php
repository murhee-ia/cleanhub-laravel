<?php

use App\Models\CleaningJobCategory;

test('guests can list active cleaning categories as a bare array', function () {
    CleaningJobCategory::factory()->create(['name' => 'Office', 'slug' => 'office']);
    CleaningJobCategory::factory()->create(['name' => 'Hotel', 'slug' => 'hotel']);

    $response = $this->getJson('/api/v1/cleaning-job-categories');

    $response->assertOk()
        ->assertJsonCount(2)
        ->assertJsonStructure([['id', 'name', 'slug']])
        ->assertJsonPath('0.slug', 'hotel')
        ->assertJsonPath('1.slug', 'office');
});

test('inactive categories are excluded from the list', function () {
    CleaningJobCategory::factory()->create(['slug' => 'active-one']);
    CleaningJobCategory::factory()->inactive()->create(['slug' => 'hidden-one']);

    $response = $this->getJson('/api/v1/cleaning-job-categories');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.slug', 'active-one');
});
