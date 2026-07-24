<?php

use App\Enums\JobPostStatus;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

test('an employer can fully edit their own draft post and publish it', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->draft()->create(['employer_id' => $employer->id, 'title' => 'Old title']);

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", [
        'title' => 'Updated title',
        'visibility' => 'published',
    ])
        ->assertOk()
        ->assertJsonPath('title', 'Updated title')
        ->assertJsonPath('visibility', 'published')
        ->assertJsonPath('status', 'open');
});

test('the status of a draft post cannot be changed', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->draft()->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['status' => 'reviewing'])
        ->assertStatus(422)->assertJsonValidationErrors('status');
});

test('media on a draft post is replaced on update', function () {
    Storage::fake('public');
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->draft()->create([
        'employer_id' => $employer->id,
        'media' => [['name' => 'old.jpg', 'path' => 'job-media/old.jpg']],
    ]);

    Sanctum::actingAs($employer);

    $this->post("/api/v1/cleaning-job-posts/{$post->id}", [
        '_method' => 'PATCH',
        'media' => [UploadedFile::fake()->image('new1.jpg'), UploadedFile::fake()->image('new2.jpg')],
    ])
        ->assertOk()
        ->assertJsonCount(2, 'media')
        ->assertJsonPath('media.0.name', 'new1.jpg');
});

test('a published post can advance its status forward', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]); // published + open

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('status', 'completed');
});

test('content edits on a published post are rejected', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['title' => 'Sneaky edit', 'pay_amount' => 999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'pay_amount']);
});

test('a closed post cannot reopen but can complete', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->status(JobPostStatus::Closed)->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['status' => 'open'])
        ->assertStatus(422)->assertJsonValidationErrors('status');

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['status' => 'completed'])
        ->assertOk()->assertJsonPath('status', 'completed');
});

test('a completed post status is terminal', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->status(JobPostStatus::Completed)->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['status' => 'reviewing'])
        ->assertStatus(422)->assertJsonValidationErrors('status');
});

test('an employer cannot set the removed status', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['status' => 'removed'])
        ->assertStatus(422)->assertJsonValidationErrors('status');
});

test('an employer cannot update another employers post', function () {
    $post = CleaningJobPost::factory()->draft()->create();

    Sanctum::actingAs(User::factory()->employer()->create());

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['title' => 'Hijacked'])
        ->assertForbidden();
});

test('a cleaner cannot update a job post', function () {
    $post = CleaningJobPost::factory()->draft()->create();

    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['title' => 'Nope'])
        ->assertForbidden();
});

test('an admin can soft-delete any job post', function () {
    $post = CleaningJobPost::factory()->create();

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->deleteJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Job post deleted.');

    expect(CleaningJobPost::withTrashed()->find($post->id)->trashed())->toBeTrue();
    expect(CleaningJobPost::find($post->id))->toBeNull();
});

test('an employer cannot delete their own job post', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);

    Sanctum::actingAs($employer);

    $this->deleteJson("/api/v1/cleaning-job-posts/{$post->id}")->assertForbidden();
    expect(CleaningJobPost::find($post->id))->not->toBeNull();
});

test('a soft-deleted post cannot be updated or viewed', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->draft()->create(['employer_id' => $employer->id]);
    $post->delete();

    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/cleaning-job-posts/{$post->id}", ['title' => 'x'])->assertNotFound();
    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")->assertNotFound();
});
