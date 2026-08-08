<?php

use App\Enums\JobPostStatus;
use App\Enums\RatingStatus;
use App\Enums\ReportStatus;
use App\Models\AuditLog;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Reports\ReportEscalatedNotification;
use App\Notifications\Reports\UserWarnedNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

test('a moderator can list the report queue', function () {
    Report::factory()->count(3)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->getJson('/api/v1/moderation/reports')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('an admin can list the report queue', function () {
    Report::factory()->count(2)->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/moderation/reports')->assertOk()->assertJsonCount(2, 'data');
});

test('a cleaner cannot reach the moderation queue', function () {
    Sanctum::actingAs(User::factory()->cleaner()->create());

    $this->getJson('/api/v1/moderation/reports')->assertForbidden();
});

test('an employer cannot reach the moderation queue', function () {
    Sanctum::actingAs(User::factory()->employer()->create());

    $this->getJson('/api/v1/moderation/reports')->assertForbidden();
});

test('the queue can be filtered by target type and status', function () {
    Report::factory()->aboutUser()->status(ReportStatus::Open)->create();
    Report::factory()->create(['status' => ReportStatus::Open]); // job_post
    Report::factory()->status(ReportStatus::Resolved)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->getJson('/api/v1/moderation/reports?type=user')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/moderation/reports?status=open')->assertOk()->assertJsonCount(2, 'data');
});

test('a moderator can resolve a report and it is audited', function () {
    $report = Report::factory()->create();
    $moderator = User::factory()->moderator()->create();
    Sanctum::actingAs($moderator);

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/resolve", [
        'note' => 'Handled, warned informally.',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'resolved')
        ->assertJsonPath('handled_by.id', $moderator->id)
        ->assertJsonPath('resolution_note', 'Handled, warned informally.');

    expect($report->fresh()->status)->toBe(ReportStatus::Resolved);
    $log = AuditLog::sole();
    expect($log->action)->toBe('report.resolved');
    expect($log->user_id)->toBe($moderator->id);
});

test('a moderator can reject a report', function () {
    $report = Report::factory()->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/reject")
        ->assertOk()
        ->assertJsonPath('status', 'rejected');

    expect($report->fresh()->status)->toBe(ReportStatus::Rejected);
});

test('escalating a report notifies the admin and is audited', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/escalate")
        ->assertOk()
        ->assertJsonPath('status', 'escalated');

    expect($report->fresh()->status)->toBe(ReportStatus::Escalated);
    Notification::assertSentTo($admin, ReportEscalatedNotification::class);
    expect(AuditLog::where('action', 'report.escalated')->exists())->toBeTrue();
});

test('hiding a reported job post removes it and resolves the report', function () {
    $post = CleaningJobPost::factory()->create(['status' => JobPostStatus::Open]);
    $report = Report::factory()->targeting($post)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/hide")
        ->assertOk()
        ->assertJsonPath('status', 'resolved');

    expect($post->fresh()->status)->toBe(JobPostStatus::Removed);
    expect($report->fresh()->status)->toBe(ReportStatus::Resolved);
    expect(AuditLog::where('action', 'content.hidden')->exists())->toBeTrue();
});

test('hiding a reported rating marks it hidden', function () {
    $rating = Rating::factory()->create(['status' => RatingStatus::Visible]);
    $report = Report::factory()->targeting($rating)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/hide")->assertOk();

    expect($rating->fresh()->status)->toBe(RatingStatus::Hidden);
});

test('a reported user cannot be hidden', function () {
    $target = User::factory()->cleaner()->create();
    $report = Report::factory()->targeting($target)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/hide")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('reportable_id');

    expect($report->fresh()->status)->toBe(ReportStatus::Open);
});

test('warning notifies the reported user and resolves the report', function () {
    Notification::fake();
    $target = User::factory()->cleaner()->create();
    $report = Report::factory()->targeting($target)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/warn", [
        'note' => 'Please keep messages professional.',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'resolved');

    Notification::assertSentTo($target, UserWarnedNotification::class);
    expect($report->fresh()->status)->toBe(ReportStatus::Resolved);
    expect(AuditLog::where('action', 'user.warned')->exists())->toBeTrue();
});

test('warning the employer behind a reported job post targets that employer', function () {
    Notification::fake();
    $employer = User::factory()->employer()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $report = Report::factory()->targeting($post)->create();
    Sanctum::actingAs(User::factory()->moderator()->create());

    $this->patchJson("/api/v1/moderation/reports/{$report->id}/warn")->assertOk();

    Notification::assertSentTo($employer, UserWarnedNotification::class);
});
