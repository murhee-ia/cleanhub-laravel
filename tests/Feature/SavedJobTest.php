<?php

use App\Enums\JobPostStatus;
use App\Models\CleaningJobPost;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('a cleaner can save an open published job', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])
        ->assertCreated()
        ->assertJsonPath('job.id', $post->id)
        ->assertJsonPath('job.status', 'open')
        ->assertJsonPath('job.title', $post->title);

    $this->assertDatabaseHas('saved_jobs', [
        'user_id' => $cleaner->id,
        'cleaning_job_post_id' => $post->id,
    ]);
});

test('a cleaner cannot save the same job twice', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cleaning_job_post_id');

    expect(SavedJob::where('cleaning_job_post_id', $post->id)->count())->toBe(1);
});

test('a cleaner cannot save a closed job', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->status(JobPostStatus::Closed)->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cleaning_job_post_id');

    $this->assertDatabaseCount('saved_jobs', 0);
});

test('a cleaner cannot save a draft job', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->draft()->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cleaning_job_post_id');
});

test('a cleaner can unsave a saved job', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->deleteJson("/api/v1/saved-jobs/{$post->id}")
        ->assertOk();

    $this->assertDatabaseMissing('saved_jobs', [
        'user_id' => $cleaner->id,
        'cleaning_job_post_id' => $post->id,
    ]);
});

test('unsaving a job the cleaner has not saved returns 404', function () {
    $cleaner = User::factory()->cleaner()->create();
    $otherCleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    SavedJob::factory()->create(['user_id' => $otherCleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->deleteJson("/api/v1/saved-jobs/{$post->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('saved_jobs', [
        'user_id' => $otherCleaner->id,
        'cleaning_job_post_id' => $post->id,
    ]);
});

test('the saved jobs list embeds the job with its live status', function () {
    $cleaner = User::factory()->cleaner()->create();
    $openPost = CleaningJobPost::factory()->create();
    $closedPost = CleaningJobPost::factory()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $openPost->id]);
    SavedJob::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $closedPost->id]);

    // The job transitions to closed after it was saved; it must still appear.
    $closedPost->update(['status' => JobPostStatus::Closed]);

    Sanctum::actingAs($cleaner);

    $response = $this->getJson('/api/v1/saved-jobs')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'saved_at', 'job' => ['id', 'title', 'status']]], 'links', 'meta']);

    $statuses = collect($response->json('data'))->pluck('job.status', 'job.id');
    expect($statuses[$openPost->id])->toBe('open');
    expect($statuses[$closedPost->id])->toBe('closed');
});

test('the saved jobs list only returns the cleaner own saves', function () {
    $cleaner = User::factory()->cleaner()->create();
    $otherCleaner = User::factory()->cleaner()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id]);
    SavedJob::factory()->count(2)->create(['user_id' => $otherCleaner->id]);
    Sanctum::actingAs($cleaner);

    $this->getJson('/api/v1/saved-jobs')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a guest cannot use the saved jobs endpoints', function () {
    $post = CleaningJobPost::factory()->create();

    $this->getJson('/api/v1/saved-jobs')->assertUnauthorized();
    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])->assertUnauthorized();
    $this->deleteJson("/api/v1/saved-jobs/{$post->id}")->assertUnauthorized();
});

test('non-cleaner roles cannot use the saved jobs endpoints', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/saved-jobs')->assertForbidden();
    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])->assertForbidden();
    $this->deleteJson("/api/v1/saved-jobs/{$post->id}")->assertForbidden();
})->with(['employer', 'moderator']);

test('the browse feed flags which jobs the cleaner has saved', function () {
    $cleaner = User::factory()->cleaner()->create();
    $savedPost = CleaningJobPost::factory()->create();
    $unsavedPost = CleaningJobPost::factory()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $savedPost->id]);
    Sanctum::actingAs($cleaner);

    $flags = collect($this->getJson('/api/v1/cleaning-job-posts')->assertOk()->json('data'))
        ->pluck('is_saved', 'id');

    expect($flags[$savedPost->id])->toBeTrue();
    expect($flags[$unsavedPost->id])->toBeFalse();
});

test('the job detail endpoint flags whether the cleaner has saved it', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('is_saved', true);
});

test('is_saved is omitted for guests and employers', function () {
    $post = CleaningJobPost::factory()->create();

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('is_saved');

    Sanctum::actingAs(User::factory()->employer()->create());
    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('is_saved');
});

test('the saved jobs list marks each embedded job as saved', function () {
    $cleaner = User::factory()->cleaner()->create();
    SavedJob::factory()->create(['user_id' => $cleaner->id]);
    Sanctum::actingAs($cleaner);

    $this->getJson('/api/v1/saved-jobs')
        ->assertOk()
        ->assertJsonPath('data.0.job.is_saved', true);
});

test('computing is_saved on the browse feed does not add a query per post', function () {
    $cleaner = User::factory()->cleaner()->create();
    Sanctum::actingAs($cleaner);

    CleaningJobPost::factory()->create();
    DB::enableQueryLog();
    $this->getJson('/api/v1/cleaning-job-posts')->assertOk();
    $single = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    // Add more posts with the log OFF so only request queries are counted.
    CleaningJobPost::factory()->count(4)->create();

    DB::enableQueryLog();
    $this->getJson('/api/v1/cleaning-job-posts')->assertOk();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($single);
});
