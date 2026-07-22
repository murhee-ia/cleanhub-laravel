<?php

use App\Enums\JobPostStatus;
use App\Models\CleaningJobPost;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('a guest can view a published job post as a bare object', function () {
    $post = CleaningJobPost::factory()->create([
        'title' => 'Residential Deep Cleaning Needed',
        'pay_amount' => 25.00,
        'pay_currency' => 'USD',
        'start_time' => '09:00:00',
    ]);

    $response = $this->getJson("/api/v1/cleaning-job-posts/{$post->id}");

    $response->assertOk()
        ->assertJsonPath('id', $post->id)
        ->assertJsonPath('title', 'Residential Deep Cleaning Needed')
        ->assertJsonPath('pay_amount', 25)
        ->assertJsonPath('pay_currency', 'USD')
        ->assertJsonPath('start_time', '09:00')
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('visibility', 'published')
        ->assertJsonPath('employer.rating_average', null)
        ->assertJsonMissing(['slug' => $post->category->slug])
        ->assertJsonMissingPath('applications_count')
        ->assertJsonStructure(['category' => ['id', 'name'], 'employer' => ['id', 'name'], 'media']);
});

test('a guest cannot view a draft job post', function () {
    $post = CleaningJobPost::factory()->draft()->create();

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")->assertNotFound();
});

test('a guest cannot view a removed job post', function () {
    $post = CleaningJobPost::factory()->status(JobPostStatus::Removed)->create();

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")->assertNotFound();
});

test('the owning employer can view their own draft job post', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->draft()->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('id', $post->id)
        ->assertJsonPath('visibility', 'draft')
        ->assertJsonPath('applications_count', 0);
});

test('a different employer cannot view someone elses draft', function () {
    $post = CleaningJobPost::factory()->draft()->create();

    Sanctum::actingAs(User::factory()->employer()->create());

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")->assertNotFound();
});

test('viewing a missing job post returns 404', function () {
    $this->getJson('/api/v1/cleaning-job-posts/999999')->assertNotFound();
});
