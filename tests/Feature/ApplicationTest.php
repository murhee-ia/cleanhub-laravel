<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

test('a cleaner can apply to an open published job with a message and a resume', function () {
    Storage::fake('public');
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', [
        'cleaning_job_post_id' => $post->id,
        'message' => 'I have five years of hotel housekeeping experience.',
        'resume' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
    ])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('job.id', $post->id)
        ->assertJsonPath('message', 'I have five years of hotel housekeeping experience.');

    $application = Application::sole();
    expect($application->user_id)->toBe($cleaner->id);
    expect($application->cleaning_job_post_id)->toBe($post->id);
    expect($application->status)->toBe(ApplicationStatus::Pending);
    Storage::disk('public')->assertExists($application->resume_path);
});

test('a cleaner can apply without a message or a resume', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])
        ->assertCreated()
        ->assertJsonPath('message', null)
        ->assertJsonPath('resume_url', null);
});

test('a cleaner cannot apply to the same job twice', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Application::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cleaning_job_post_id');

    expect(Application::where('cleaning_job_post_id', $post->id)->count())->toBe(1);
});

test('a withdrawn or rejected application still blocks re-applying', function (ApplicationStatus $status) {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Application::factory()->status($status)->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cleaning_job_post_id');

    expect(Application::where('cleaning_job_post_id', $post->id)->count())->toBe(1);
})->with([ApplicationStatus::Withdrawn, ApplicationStatus::Rejected]);

test('the duplicate and closed-job rejections are told apart by their message', function () {
    $cleaner = User::factory()->cleaner()->create();
    $openPost = CleaningJobPost::factory()->create();
    $closedPost = CleaningJobPost::factory()->status(JobPostStatus::Closed)->create();
    Application::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $openPost->id]);
    Sanctum::actingAs($cleaner);

    $duplicate = $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $openPost->id])
        ->assertStatus(422)
        ->json('errors.cleaning_job_post_id.0');

    $closed = $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $closedPost->id])
        ->assertStatus(422)
        ->json('errors.cleaning_job_post_id.0');

    expect($duplicate)->toBe('You have already applied to this job.');
    expect($closed)->toBe('This job is no longer accepting applications.');
});

test('a cleaner cannot apply to a job that is not open and published', function (string $state) {
    $cleaner = User::factory()->cleaner()->create();
    $post = $state === 'draft'
        ? CleaningJobPost::factory()->draft()->create()
        : CleaningJobPost::factory()->status(JobPostStatus::from($state))->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cleaning_job_post_id');

    $this->assertDatabaseCount('applications', 0);
})->with(['draft', 'closed', 'completed', 'removed']);

test('an employer cannot apply, which is what blocks applying to your own job', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    Sanctum::actingAs($employer);

    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])
        ->assertForbidden();

    $this->assertDatabaseCount('applications', 0);
});

test('a message longer than 101 words is rejected', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', [
        'cleaning_job_post_id' => $post->id,
        'message' => implode(' ', array_fill(0, 102, 'word')),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});

test('a resume that is not a pdf is rejected', function () {
    Storage::fake('public');
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', [
        'cleaning_job_post_id' => $post->id,
        'resume' => UploadedFile::fake()->image('resume.jpg'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('resume');

    $this->assertDatabaseCount('applications', 0);
});

test('a cleaner can withdraw a pending application', function () {
    $cleaner = User::factory()->cleaner()->create();
    $application = Application::factory()->create(['user_id' => $cleaner->id]);
    Sanctum::actingAs($cleaner);

    $this->deleteJson("/api/v1/applications/{$application->id}")->assertOk();

    // The row survives the withdrawal so the unique constraint keeps blocking
    // a re-apply.
    expect($application->refresh()->status)->toBe(ApplicationStatus::Withdrawn);
});

test('a cleaner cannot withdraw an application that is no longer pending', function () {
    $cleaner = User::factory()->cleaner()->create();
    $application = Application::factory()->status(ApplicationStatus::Accepted)->create(['user_id' => $cleaner->id]);
    Sanctum::actingAs($cleaner);

    $this->deleteJson("/api/v1/applications/{$application->id}")->assertForbidden();

    expect($application->refresh()->status)->toBe(ApplicationStatus::Accepted);
});

test('a cleaner cannot withdraw someone else application', function () {
    $cleaner = User::factory()->cleaner()->create();
    $application = Application::factory()->create();
    Sanctum::actingAs($cleaner);

    $this->deleteJson("/api/v1/applications/{$application->id}")->assertForbidden();
});

test('the applications list only returns the cleaner own applications', function () {
    $cleaner = User::factory()->cleaner()->create();
    Application::factory()->count(2)->create(['user_id' => $cleaner->id]);
    Application::factory()->count(3)->create();
    Sanctum::actingAs($cleaner);

    $this->getJson('/api/v1/applications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'status', 'message', 'job' => ['id', 'title', 'status']]], 'links', 'meta']);
});

test('the applications list can be filtered to each status tab', function (ApplicationStatus $status) {
    $cleaner = User::factory()->cleaner()->create();

    foreach (ApplicationStatus::cases() as $case) {
        Application::factory()->status($case)->create(['user_id' => $cleaner->id]);
    }

    Sanctum::actingAs($cleaner);

    $this->getJson("/api/v1/applications?status={$status->value}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', $status->value);
})->with(ApplicationStatus::cases());

test('an employer can list the applicants of their own job post', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    Application::factory()->count(2)->create(['cleaning_job_post_id' => $post->id]);
    Application::factory()->count(3)->create();
    Sanctum::actingAs($employer);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}/applications")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'status', 'cleaner' => ['id', 'full_name', 'rating_average']]]]);
});

test('an employer cannot list the applicants of another employer job post', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create();
    Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}/applications")->assertForbidden();
});

test('a withdrawn applicant drops out of the employer applicant list but is still counted', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $active = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Application::factory()->status(ApplicationStatus::Withdrawn)->count(2)->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $response = $this->getJson("/api/v1/cleaning-job-posts/{$post->id}/applications")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('meta.withdrawn_count', 2);

    // The extra meta key must not clobber the pagination meta it merges into.
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('meta.current_page'))->toBe(1);
    expect($response->json('meta.per_page'))->toBe(15);
});

test('withdrawn_count is zero when no applicant has withdrawn', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}/applications")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.withdrawn_count', 0);
});

test('a cleaner still sees their own withdrawn application in their own list', function () {
    $cleaner = User::factory()->cleaner()->create();
    $withdrawn = Application::factory()->status(ApplicationStatus::Withdrawn)->create(['user_id' => $cleaner->id]);
    Sanctum::actingAs($cleaner);

    $this->getJson('/api/v1/applications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $withdrawn->id);

    $this->getJson('/api/v1/applications?status=withdrawn')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a single application is readable by both the owning cleaner and the owning employer', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);

    Sanctum::actingAs($application->user);
    $this->getJson("/api/v1/applications/{$application->id}/detail")
        ->assertOk()
        ->assertJsonPath('id', $application->id);

    Sanctum::actingAs($employer);
    $this->getJson("/api/v1/applications/{$application->id}/detail")
        ->assertOk()
        ->assertJsonPath('id', $application->id);
});

test('a single application is not readable by an unrelated user', function (string $role) {
    $application = Application::factory()->create();
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->getJson("/api/v1/applications/{$application->id}/detail")->assertForbidden();
})->with(['cleaner', 'employer', 'moderator']);

test('an employer can accept and reject applicants on their own job post', function (string $action, ApplicationStatus $expected) {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/{$action}")
        ->assertOk()
        ->assertJsonPath('status', $expected->value);

    expect($application->refresh()->status)->toBe($expected);
})->with([
    ['accept', ApplicationStatus::Accepted],
    ['reject', ApplicationStatus::Rejected],
]);

test('an already decided application cannot be decided again', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->status(ApplicationStatus::Accepted)->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/reject")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($application->refresh()->status)->toBe(ApplicationStatus::Accepted);
});

test('an employer cannot decide on another employer applicants', function (string $action) {
    $application = Application::factory()->create();
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->patchJson("/api/v1/applications/{$application->id}/{$action}")->assertForbidden();

    expect($application->refresh()->status)->toBe(ApplicationStatus::Pending);
})->with(['accept', 'reject']);

test('a cleaner cannot decide on their own application', function () {
    $application = Application::factory()->create();
    Sanctum::actingAs($application->user);

    $this->patchJson("/api/v1/applications/{$application->id}/accept")->assertForbidden();
});

test('an employer can send the cleaner a message when deciding', function (string $action, ApplicationStatus $expected) {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/{$action}", ['message' => 'See you Monday at 8am.'])
        ->assertOk()
        ->assertJsonPath('status', $expected->value)
        ->assertJsonPath('decision_message', 'See you Monday at 8am.');

    expect($application->refresh()->decision_message)->toBe('See you Monday at 8am.');
})->with([
    ['accept', ApplicationStatus::Accepted],
    ['reject', ApplicationStatus::Rejected],
]);

test('deciding without a message leaves the decision message null', function (string $action) {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/{$action}")
        ->assertOk()
        ->assertJsonPath('decision_message', null);

    expect($application->refresh()->decision_message)->toBeNull();
})->with(['accept', 'reject']);

test('a decision message longer than 2000 characters is rejected', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/accept", ['message' => str_repeat('a', 2001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');

    expect($application->refresh()->status)->toBe(ApplicationStatus::Pending);
});

test('the decision message is readable by both sides, unlike the private note', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/accept", ['message' => 'Welcome aboard.'])->assertOk();
    $this->patchJson("/api/v1/applications/{$application->id}/note", ['note' => 'Strong hotel background.'])
        ->assertOk()
        ->assertJsonPath('decision_message', 'Welcome aboard.')
        ->assertJsonPath('private_note', 'Strong hotel background.');

    Sanctum::actingAs($application->user);
    $this->getJson("/api/v1/applications/{$application->id}/detail")
        ->assertOk()
        ->assertJsonPath('decision_message', 'Welcome aboard.')
        ->assertJsonMissingPath('private_note');

    Sanctum::actingAs($employer);
    $this->getJson("/api/v1/applications/{$application->id}/detail")
        ->assertOk()
        ->assertJsonPath('decision_message', 'Welcome aboard.')
        ->assertJsonPath('private_note', 'Strong hotel background.');
});

test('the owning employer can set a private note that the cleaner never sees', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/note", ['note' => 'Strong hotel background.'])
        ->assertOk()
        ->assertJsonPath('private_note', 'Strong hotel background.');

    Sanctum::actingAs($application->user);
    $this->getJson("/api/v1/applications/{$application->id}/detail")
        ->assertOk()
        ->assertJsonMissingPath('private_note');
});

test('another employer cannot set a private note', function () {
    $application = Application::factory()->create();
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->patchJson("/api/v1/applications/{$application->id}/note", ['note' => 'Nope.'])->assertForbidden();
});

test('a guest cannot use any application endpoint', function () {
    $post = CleaningJobPost::factory()->create();
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id]);

    $this->getJson('/api/v1/applications')->assertUnauthorized();
    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])->assertUnauthorized();
    $this->deleteJson("/api/v1/applications/{$application->id}")->assertUnauthorized();
    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}/applications")->assertUnauthorized();
    $this->getJson("/api/v1/applications/{$application->id}/detail")->assertUnauthorized();
    $this->patchJson("/api/v1/applications/{$application->id}/accept")->assertUnauthorized();
    $this->patchJson("/api/v1/applications/{$application->id}/reject")->assertUnauthorized();
    $this->patchJson("/api/v1/applications/{$application->id}/note", ['note' => 'x'])->assertUnauthorized();
});

test('non-cleaner roles cannot use the cleaner application endpoints', function (string $role) {
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->getJson('/api/v1/applications')->assertForbidden();
    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])->assertForbidden();
})->with(['employer', 'moderator']);

test('the browse feed flags which jobs the cleaner has applied to and with what status', function () {
    $cleaner = User::factory()->cleaner()->create();
    $appliedPost = CleaningJobPost::factory()->create();
    $untouchedPost = CleaningJobPost::factory()->create();
    Application::factory()->status(ApplicationStatus::Accepted)->create([
        'user_id' => $cleaner->id,
        'cleaning_job_post_id' => $appliedPost->id,
    ]);
    Sanctum::actingAs($cleaner);

    $data = collect($this->getJson('/api/v1/cleaning-job-posts')->assertOk()->json('data'))->keyBy('id');

    expect($data[$appliedPost->id]['has_applied'])->toBeTrue();
    expect($data[$appliedPost->id]['application_status'])->toBe('accepted');
    expect($data[$untouchedPost->id]['has_applied'])->toBeFalse();
    expect($data[$untouchedPost->id])->not->toHaveKey('application_status');
});

test('a rejected or withdrawn application still reports has_applied on the job detail', function (ApplicationStatus $status) {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Application::factory()->status($status)->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('has_applied', true)
        ->assertJsonPath('application_status', $status->value);
})->with([ApplicationStatus::Rejected, ApplicationStatus::Withdrawn]);

test('has_applied is omitted for guests and employers', function () {
    $post = CleaningJobPost::factory()->create();

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('has_applied');

    Sanctum::actingAs(User::factory()->employer()->create());
    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('has_applied');
});

test('the saved jobs list and the applications list agree on the viewer flags', function () {
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Application::factory()->create(['user_id' => $cleaner->id, 'cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/saved-jobs', ['cleaning_job_post_id' => $post->id])
        ->assertCreated()
        ->assertJsonPath('job.is_saved', true)
        ->assertJsonPath('job.has_applied', true)
        ->assertJsonPath('job.application_status', 'pending');

    $this->getJson('/api/v1/saved-jobs')
        ->assertOk()
        ->assertJsonPath('data.0.job.has_applied', true);

    $this->getJson('/api/v1/applications')
        ->assertOk()
        ->assertJsonPath('data.0.job.is_saved', true)
        ->assertJsonPath('data.0.job.has_applied', true);
});

test('the owning employer sees a real applications count on their job post', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    Application::factory()->count(3)->create(['cleaning_job_post_id' => $post->id]);
    Sanctum::actingAs($employer);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('applications_count', 3);

    $this->getJson('/api/v1/cleaning-job-posts/mine')
        ->assertOk()
        ->assertJsonPath('data.0.applications_count', 3);
});

test('applications_count is omitted for everyone but the owning employer', function () {
    $post = CleaningJobPost::factory()->create();
    Application::factory()->create(['cleaning_job_post_id' => $post->id]);

    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('applications_count');

    Sanctum::actingAs(User::factory()->cleaner()->create());
    $this->getJson("/api/v1/cleaning-job-posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('applications_count');
});

test('computing the application flags on the browse feed does not add a query per post', function () {
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
