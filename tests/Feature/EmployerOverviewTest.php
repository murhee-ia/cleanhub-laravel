<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-15 09:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('an employer with no posts receives the job-post empty dashboard', function () {
    $employer = User::factory()->employer()->create();

    Sanctum::actingAs($employer);

    $this->getJson('/api/v1/employer/overview')
        ->assertOk()
        ->assertJsonPath('summary.total_posts', 0)
        ->assertJsonPath('summary.active_posts', 0)
        ->assertJsonPath('summary.pending_applicants', 0)
        ->assertJsonPath('job_pipeline.draft', 0)
        ->assertJsonPath('applicant_pipeline.total', 0)
        ->assertJsonPath('attention.unrated_cleaners', 0)
        ->assertJsonPath('performance.range', '30d')
        ->assertJsonPath('performance.posts_created', 0)
        ->assertJsonPath('reputation.average_rating', null)
        ->assertJsonCount(0, 'priority_jobs')
        ->assertJsonCount(0, 'upcoming_jobs')
        ->assertJsonCount(0, 'recent_applications');

});

test('the overview scopes management and analysis data to the authenticated employer', function () {
    $employer = User::factory()->employer()->create();
    $otherEmployer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();

    $draft = CleaningJobPost::factory()->draft()->for($employer, 'employer')->create();
    $open = CleaningJobPost::factory()->for($employer, 'employer')->create([
        'application_deadline' => '2026-08-17',
        'schedule_date' => '2026-08-25',
    ]);
    $reviewing = CleaningJobPost::factory()->status(JobPostStatus::Reviewing)->for($employer, 'employer')->create([
        'application_deadline' => null,
        'schedule_date' => '2026-08-20',
    ]);
    $closed = CleaningJobPost::factory()->status(JobPostStatus::Closed)->for($employer, 'employer')->create([
        'schedule_date' => '2026-08-14',
    ]);
    $completed = CleaningJobPost::factory()->status(JobPostStatus::Completed)->for($employer, 'employer')->create([
        'schedule_date' => '2026-08-10',
    ]);

    Application::factory()->for($open, 'cleaningJobPost')->status(ApplicationStatus::Pending)->create();
    Application::factory()->for($reviewing, 'cleaningJobPost')->status(ApplicationStatus::Accepted)->create();
    Application::factory()->for($closed, 'cleaningJobPost')->status(ApplicationStatus::Rejected)->create();
    $completedApplication = Application::factory()
        ->for($completed, 'cleaningJobPost')
        ->for($cleaner, 'user')
        ->status(ApplicationStatus::Completed)
        ->create();

    Rating::factory()->create([
        'application_id' => $completedApplication->id,
        'reviewer_id' => $cleaner->id,
        'reviewee_id' => $employer->id,
        'stars' => 5,
    ]);

    $otherJob = CleaningJobPost::factory()->for($otherEmployer, 'employer')->create();
    Application::factory()->count(2)->for($otherJob, 'cleaningJobPost')->create();

    Sanctum::actingAs($employer);

    $response = $this->getJson('/api/v1/employer/overview?range=30d')
        ->assertOk()
        ->assertJsonPath('summary.total_posts', 5)
        ->assertJsonPath('summary.active_posts', 2)
        ->assertJsonPath('summary.pending_applicants', 1)
        ->assertJsonPath('summary.accepted_cleaners', 2)
        ->assertJsonPath('summary.upcoming_jobs', 1)
        ->assertJsonPath('summary.completed_jobs', 1)
        ->assertJsonPath('job_pipeline.draft', 1)
        ->assertJsonPath('job_pipeline.open', 1)
        ->assertJsonPath('job_pipeline.reviewing', 1)
        ->assertJsonPath('job_pipeline.closed', 1)
        ->assertJsonPath('job_pipeline.completed', 1)
        ->assertJsonPath('applicant_pipeline.total', 4)
        ->assertJsonPath('applicant_pipeline.pending', 1)
        ->assertJsonPath('applicant_pipeline.accepted', 1)
        ->assertJsonPath('applicant_pipeline.rejected', 1)
        ->assertJsonPath('applicant_pipeline.completed', 1)
        ->assertJsonPath('attention.draft_posts', 1)
        ->assertJsonPath('attention.closing_soon', 1)
        ->assertJsonPath('attention.awaiting_completion', 1)
        ->assertJsonPath('attention.unrated_cleaners', 1)
        ->assertJsonPath('performance.posts_created', 5)
        ->assertJsonPath('performance.applications_received', 4)
        ->assertJsonPath('reputation.average_rating', 5)
        ->assertJsonPath('reputation.rating_count', 1)
        ->assertJsonPath('reputation.completed_relationships', 1)
        ->assertJsonPath('upcoming_jobs.0.id', $reviewing->id);

    expect(collect($response->json('priority_jobs'))->pluck('id'))
        ->toContain($draft->id, $open->id, $reviewing->id, $closed->id)
        ->not->toContain($completed->id, $otherJob->id);
    expect(collect($response->json('recent_applications'))->pluck('job.id')->unique()->all())
        ->not->toContain($otherJob->id);
});

test('performance ranges filter older activity and reject unsupported values', function () {
    $employer = User::factory()->employer()->create();

    CleaningJobPost::factory()->for($employer, 'employer')->create([
        'created_at' => '2026-08-14 09:00:00',
    ]);
    CleaningJobPost::factory()->for($employer, 'employer')->create([
        'created_at' => '2026-07-01 09:00:00',
    ]);

    Sanctum::actingAs($employer);

    $this->getJson('/api/v1/employer/overview?range=7d')
        ->assertOk()
        ->assertJsonPath('performance.posts_created', 1)
        ->assertJsonCount(7, 'performance.application_trend');

    $this->getJson('/api/v1/employer/overview?range=quarter')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('range');
});

test('the employer overview requires authentication and the employer role', function () {
    $this->getJson('/api/v1/employer/overview')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson('/api/v1/employer/overview')->assertForbidden();
});
