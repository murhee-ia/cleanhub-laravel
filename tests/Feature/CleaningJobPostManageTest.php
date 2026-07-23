<?php

use App\Models\CleaningJobCategory;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function validJobPostPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Residential Deep Cleaning Needed',
        'cleaning_job_category_id' => CleaningJobCategory::factory()->create()->id,
        'description' => 'Full description of the cleaning task.',
        'country' => 'Philippines',
        'city' => 'Cebu',
        'schedule_date' => now()->addWeek()->toDateString(),
    ], $overrides);
}

test('an employer can create a job post with media', function () {
    Storage::fake('public');
    $employer = User::factory()->employer()->create();
    Sanctum::actingAs($employer);

    $response = $this->post('/api/v1/cleaning-job-posts', validJobPostPayload([
        'visibility' => 'published',
        'pay_amount' => 30,
        'pay_currency' => 'USD',
        'start_time' => '09:00',
        'media' => [UploadedFile::fake()->image('before.jpg')],
    ]));

    $response->assertCreated()
        ->assertJsonPath('title', 'Residential Deep Cleaning Needed')
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('visibility', 'published')
        ->assertJsonPath('pay_amount', 30)
        ->assertJsonPath('start_time', '09:00')
        ->assertJsonCount(1, 'media')
        ->assertJsonPath('applications_count', 0);

    $post = CleaningJobPost::first();
    expect($post->employer_id)->toBe($employer->id);
    Storage::disk('public')->assertExists($post->media[0]['path']);
});

test('a cleaner cannot create a job post', function () {
    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->postJson('/api/v1/cleaning-job-posts', validJobPostPayload())
        ->assertForbidden();
});

test('a guest cannot create a job post', function () {
    $this->postJson('/api/v1/cleaning-job-posts', validJobPostPayload())
        ->assertUnauthorized();
});

test('a job post requires title, category, description, location, and date', function () {
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->postJson('/api/v1/cleaning-job-posts', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'cleaning_job_category_id', 'description', 'country', 'city', 'schedule_date']);
});

test('a job post title over 101 words is rejected', function () {
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->postJson('/api/v1/cleaning-job-posts', validJobPostPayload(['title' => trim(str_repeat('word ', 102))]))
        ->assertStatus(422)->assertJsonValidationErrors('title');
});

test('non-image media is rejected', function () {
    Storage::fake('public');
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->post('/api/v1/cleaning-job-posts', validJobPostPayload([
        'media' => [UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')],
    ]))->assertStatus(422)->assertJsonValidationErrors('media.0');
});

test('a schedule date in the past is rejected', function () {
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->postJson('/api/v1/cleaning-job-posts', validJobPostPayload(['schedule_date' => now()->subDay()->toDateString()]))
        ->assertStatus(422)->assertJsonValidationErrors('schedule_date');
});

test('an application deadline after the schedule date is rejected', function () {
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->postJson('/api/v1/cleaning-job-posts', validJobPostPayload([
        'schedule_date' => now()->addWeek()->toDateString(),
        'application_deadline' => now()->addWeeks(2)->toDateString(),
    ]))->assertStatus(422)->assertJsonValidationErrors('application_deadline');
});

test('an employer lists only their own posts via mine', function () {
    $employer = User::factory()->employer()->create();
    CleaningJobPost::factory()->count(2)->create(['employer_id' => $employer->id]);
    CleaningJobPost::factory()->draft()->create(['employer_id' => $employer->id]);
    CleaningJobPost::factory()->count(3)->create();

    Sanctum::actingAs($employer);

    $this->getJson('/api/v1/cleaning-job-posts/mine')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('a guest cannot access the mine listing', function () {
    $this->getJson('/api/v1/cleaning-job-posts/mine')->assertUnauthorized();
});
