<?php

use App\Enums\ReportStatus;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Reports\NewReportNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

test('any authenticated user can report a job post', function () {
    Notification::fake();
    $reporter = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => $post->id,
        'reason' => 'This listing looks like a scam.',
    ])
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('reportable_type', 'job_post')
        ->assertJsonPath('reporter.id', $reporter->id);

    $report = Report::sole();
    expect($report->reportable_id)->toBe($post->id);
    expect($report->status)->toBe(ReportStatus::Open);
});

test('a user can be reported', function () {
    Notification::fake();
    $reporter = User::factory()->employer()->create();
    $target = User::factory()->cleaner()->create();
    Sanctum::actingAs($reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'user',
        'reportable_id' => $target->id,
        'reason' => 'Abusive messages.',
    ])->assertCreated()->assertJsonPath('reportable_type', 'user');
});

test('a rating can be reported', function () {
    Notification::fake();
    $reporter = User::factory()->employer()->create();
    $rating = Rating::factory()->create();
    Sanctum::actingAs($reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'rating',
        'reportable_id' => $rating->id,
        'reason' => 'Defamatory review.',
    ])->assertCreated()->assertJsonPath('reportable_type', 'rating');
});

test('a guest cannot report', function () {
    $post = CleaningJobPost::factory()->create();

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => $post->id,
        'reason' => 'Nope.',
    ])->assertUnauthorized();
});

test('reporting a target that does not exist is a 404', function () {
    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => 99999,
        'reason' => 'Missing.',
    ])->assertNotFound();
});

test('an unknown reportable type is rejected', function () {
    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'invoice',
        'reportable_id' => 1,
        'reason' => 'Wrong type.',
    ])->assertStatus(422)->assertJsonValidationErrorFor('reportable_type');
});

test('a user cannot report their own job post', function () {
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    Sanctum::actingAs($employer);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => $post->id,
        'reason' => 'Reporting myself.',
    ])->assertStatus(422)->assertJsonValidationErrorFor('reportable_id');
});

test('a second open report on the same target is rejected as a duplicate', function () {
    Notification::fake();
    $reporter = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Report::factory()->targeting($post)->create([
        'reporter_id' => $reporter->id,
        'status' => ReportStatus::Open,
    ]);
    Sanctum::actingAs($reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => $post->id,
        'reason' => 'Again.',
    ])->assertStatus(422)->assertJsonValidationErrorFor('reportable_id');
});

test('a target can be reported again once the earlier report is closed', function () {
    Notification::fake();
    $reporter = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Report::factory()->targeting($post)->create([
        'reporter_id' => $reporter->id,
        'status' => ReportStatus::Resolved,
    ]);
    Sanctum::actingAs($reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => $post->id,
        'reason' => 'It came back.',
    ])->assertCreated();
});

test('filing a report notifies every moderator', function () {
    Notification::fake();
    $moderators = User::factory()->moderator()->count(2)->create();
    $reporter = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create();
    Sanctum::actingAs($reporter);

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'job_post',
        'reportable_id' => $post->id,
        'reason' => 'Spam.',
    ])->assertCreated();

    Notification::assertSentTo($moderators, NewReportNotification::class);
    Notification::assertNotSentTo($reporter, NewReportNotification::class);
});
