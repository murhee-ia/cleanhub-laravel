<?php

use App\Enums\JobPostStatus;
use App\Models\CleaningJobCategory;
use App\Models\CleaningJobPost;
use App\Models\User;

test('guests can browse published open posts in a paginated envelope', function () {
    CleaningJobPost::factory()->count(3)->create();
    CleaningJobPost::factory()->draft()->create();
    CleaningJobPost::factory()->status(JobPostStatus::Closed)->create();

    $response = $this->getJson('/api/v1/cleaning-job-posts');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'title', 'category' => ['id', 'name'], 'employer' => ['id', 'name']]],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('search matches title, description, and employer name', function () {
    $employer = User::factory()->employer()->create(['name' => 'Sparkle Cleaning Co']);
    CleaningJobPost::factory()->create(['employer_id' => $employer->id, 'title' => 'Generic job']);
    CleaningJobPost::factory()->create(['title' => 'Hotel Housekeeping Needed', 'description' => 'x']);
    CleaningJobPost::factory()->create(['title' => 'Unrelated', 'description' => 'x']);

    $this->getJson('/api/v1/cleaning-job-posts?search=Sparkle')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/cleaning-job-posts?search=Housekeeping')
        ->assertOk()->assertJsonCount(1, 'data');
});

test('posts can be filtered by category, country, and city', function () {
    $category = CleaningJobCategory::factory()->create();
    CleaningJobPost::factory()->create(['cleaning_job_category_id' => $category->id, 'country' => 'Italy', 'city' => 'Rome']);
    CleaningJobPost::factory()->create(['country' => 'Japan', 'city' => 'Tokyo']);

    $this->getJson("/api/v1/cleaning-job-posts?category_id={$category->id}")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/cleaning-job-posts?country=Japan')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.city', 'Tokyo');
});

test('posts can be sorted by soonest schedule and highest pay', function () {
    $soon = CleaningJobPost::factory()->create(['schedule_date' => now()->addDays(2)->toDateString(), 'pay_amount' => 10]);
    $later = CleaningJobPost::factory()->create(['schedule_date' => now()->addDays(30)->toDateString(), 'pay_amount' => 500]);

    $this->getJson('/api/v1/cleaning-job-posts?sort=soonest')
        ->assertOk()->assertJsonPath('data.0.id', $soon->id);

    $this->getJson('/api/v1/cleaning-job-posts?sort=high_pay')
        ->assertOk()->assertJsonPath('data.0.id', $later->id);
});

test('per_page controls pagination size', function () {
    CleaningJobPost::factory()->count(5)->create();

    $this->getJson('/api/v1/cleaning-job-posts?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5);
});

test('an invalid sort value is rejected', function () {
    $this->getJson('/api/v1/cleaning-job-posts?sort=cheapest')
        ->assertStatus(422)->assertJsonValidationErrors('sort');
});
