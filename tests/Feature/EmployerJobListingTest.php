<?php

use App\Enums\JobPostStatus;
use App\Models\CleaningJobPost;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('guests cannot view an employer job listing', function () {
    $employer = User::factory()->employer()->create();

    $this->getJson("/api/v1/employers/{$employer->id}/cleaning-job-posts")
        ->assertUnauthorized();
});

test('an authenticated user sees an employer published posts across all non-removed statuses', function () {
    $employer = User::factory()->employer()->create();
    CleaningJobPost::factory()->create(['employer_id' => $employer->id]); // published + open
    CleaningJobPost::factory()->status(JobPostStatus::Closed)->create(['employer_id' => $employer->id]);
    CleaningJobPost::factory()->status(JobPostStatus::Completed)->create(['employer_id' => $employer->id]);

    // excluded: draft (not public) and removed (hidden)
    CleaningJobPost::factory()->draft()->create(['employer_id' => $employer->id]);
    CleaningJobPost::factory()->status(JobPostStatus::Removed)->create(['employer_id' => $employer->id]);

    // another employer's post must not appear
    CleaningJobPost::factory()->create();

    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson("/api/v1/employers/{$employer->id}/cleaning-job-posts")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'title', 'status', 'category' => ['id', 'name'], 'employer' => ['id', 'name']]],
            'links',
            'meta',
        ]);
});

test('requesting a non-employer id returns 404', function () {
    $cleaner = User::factory()->cleaner()->create();

    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson("/api/v1/employers/{$cleaner->id}/cleaning-job-posts")->assertNotFound();
    $this->getJson('/api/v1/employers/999999/cleaning-job-posts')->assertNotFound();
});
