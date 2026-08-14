<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Enums\RatingStatus;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('a cleaner can rate the employer of a completed job', function () {
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->status(ApplicationStatus::Completed)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/ratings', [
        'application_id' => $application->id,
        'stars' => 5,
        'text' => 'Paid on time, clear instructions.',
    ])
        ->assertCreated()
        ->assertJsonPath('stars', 5)
        ->assertJsonPath('text', 'Paid on time, clear instructions.')
        ->assertJsonPath('reviewer.id', $cleaner->id);

    $rating = Rating::sole();
    expect($rating->application_id)->toBe($application->id);
    expect($rating->reviewer_id)->toBe($cleaner->id);
    expect($rating->reviewee_id)->toBe($employer->id);
    expect($rating->status)->toBe(RatingStatus::Visible);
});

test('an employer can rate the cleaner of a completed job', function () {
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->status(JobPostStatus::Completed)->create([
        'employer_id' => $employer->id,
    ]);
    $application = Application::factory()->status(ApplicationStatus::Accepted)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);
    Sanctum::actingAs($employer);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 4])
        ->assertCreated()
        ->assertJsonPath('reviewer.id', $employer->id);

    $rating = Rating::sole();
    expect($rating->reviewer_id)->toBe($employer->id);
    expect($rating->reviewee_id)->toBe($cleaner->id);
});

test('a cleaner cannot rate before completing their application', function (ApplicationStatus $status) {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->status($status)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])
        ->assertForbidden();

    expect(Rating::count())->toBe(0);
})->with([ApplicationStatus::Pending, ApplicationStatus::Accepted, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn]);

test('a reviewer cannot rate the same completed application twice', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->status(ApplicationStatus::Completed)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])->assertCreated();

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('application_id');

    expect(Rating::count())->toBe(1);
});

test('both directions of the same completed application can be rated independently', function () {
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->status(JobPostStatus::Completed)->create([
        'employer_id' => $employer->id,
    ]);
    $application = Application::factory()->status(ApplicationStatus::Completed)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);

    Sanctum::actingAs($cleaner);
    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])->assertCreated();

    Sanctum::actingAs($employer);
    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 4])->assertCreated();

    expect(Rating::count())->toBe(2);
});

test('a user who is neither party to the application cannot rate it', function () {
    $stranger = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->status(ApplicationStatus::Completed)->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($stranger);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])
        ->assertForbidden();

    expect(Rating::count())->toBe(0);
});

test('a moderator cannot submit a rating', function () {
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->status(ApplicationStatus::Completed)->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])
        ->assertForbidden();
});

test("a cleaner's average rating and review count are accurate on their public profile", function () {
    $cleaner = User::factory()->cleaner()->create();

    Rating::factory()->create(['reviewee_id' => $cleaner->id, 'stars' => 5]);
    Rating::factory()->create(['reviewee_id' => $cleaner->id, 'stars' => 4]);
    // A hidden rating (moderated away) never counts toward the public average.
    Rating::factory()->status(RatingStatus::Hidden)->create(['reviewee_id' => $cleaner->id, 'stars' => 1]);

    Sanctum::actingAs(User::factory()->employer()->create());

    $this->getJson("/api/v1/cleaners/{$cleaner->id}")
        ->assertOk()
        ->assertJsonPath('rating_average', 4.5)
        ->assertJsonPath('rating_count', 2);
});

test("an employer's average rating and review count are accurate on their public profile", function () {
    $employer = User::factory()->employer()->create();

    Rating::factory()->create(['reviewee_id' => $employer->id, 'stars' => 2]);
    Rating::factory()->create(['reviewee_id' => $employer->id, 'stars' => 3]);

    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson("/api/v1/employers/{$employer->id}")
        ->assertOk()
        ->assertJsonPath('rating_average', 2.5)
        ->assertJsonPath('rating_count', 2);
});

test('a profile with no ratings reports a null average and zero count', function () {
    $cleaner = User::factory()->cleaner()->create();
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->getJson("/api/v1/cleaners/{$cleaner->id}")
        ->assertOk()
        ->assertJsonPath('rating_average', null)
        ->assertJsonPath('rating_count', 0);
});

test("a cleaner's reviews list only returns visible ratings, newest first", function () {
    $cleaner = User::factory()->cleaner()->create();
    $older = Rating::factory()->create(['reviewee_id' => $cleaner->id, 'created_at' => now()->subDay()]);
    $newer = Rating::factory()->create(['reviewee_id' => $cleaner->id, 'created_at' => now()]);
    Rating::factory()->status(RatingStatus::Hidden)->create(['reviewee_id' => $cleaner->id]);
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->getJson("/api/v1/cleaners/{$cleaner->id}/ratings")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);
});

test("an employer's reviews list is scoped to that employer alone", function () {
    $employer = User::factory()->employer()->create();
    $otherEmployer = User::factory()->employer()->create();
    Rating::factory()->create(['reviewee_id' => $employer->id]);
    Rating::factory()->create(['reviewee_id' => $otherEmployer->id]);
    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson("/api/v1/employers/{$employer->id}/ratings")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a guest cannot submit or list ratings', function () {
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->status(ApplicationStatus::Completed)->create(['cleaning_job_post_id' => $post->id]);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])->assertUnauthorized();
    $this->getJson('/api/v1/cleaners/1/ratings')->assertUnauthorized();
});

test("an application's viewer_has_rated flag flips after the viewer submits, independently per side", function () {
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->status(JobPostStatus::Completed)->create([
        'employer_id' => $employer->id,
    ]);
    $application = Application::factory()->status(ApplicationStatus::Completed)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);

    Sanctum::actingAs($cleaner);
    $this->getJson('/api/v1/applications')->assertJsonPath('data.0.viewer_has_rated', false);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => 5])->assertCreated();

    $this->getJson('/api/v1/applications')->assertJsonPath('data.0.viewer_has_rated', true);

    Sanctum::actingAs($employer);
    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}/applications")
        ->assertJsonPath('data.0.viewer_has_rated', false);
});

test('stars must be between 1 and 5', function (int $stars) {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->status(ApplicationStatus::Completed)->create([
        'cleaning_job_post_id' => $post->id,
        'user_id' => $cleaner->id,
    ]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/ratings', ['application_id' => $application->id, 'stars' => $stars])
        ->assertStatus(422)
        ->assertJsonValidationErrors('stars');
})->with([0, 6]);
