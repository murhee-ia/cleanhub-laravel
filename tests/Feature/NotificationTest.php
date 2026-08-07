<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\User;
use App\Notifications\Applications\ApplicationAccepted;
use App\Notifications\Applications\ApplicationRejected;
use App\Notifications\Applications\ApplicationWithdrawn;
use App\Notifications\Applications\JobReminder;
use App\Notifications\Applications\NewApplicant;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

test('applying to a job notifies the employer', function () {
    Notification::fake();
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    Sanctum::actingAs($cleaner);

    $this->postJson('/api/v1/applications', ['cleaning_job_post_id' => $post->id])->assertCreated();

    Notification::assertSentTo($employer, NewApplicant::class);
    Notification::assertNotSentTo($cleaner, NewApplicant::class);
});

test('withdrawing an application notifies the employer', function () {
    Notification::fake();
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id, 'user_id' => $cleaner->id]);
    Sanctum::actingAs($cleaner);

    $this->deleteJson("/api/v1/applications/{$application->id}")->assertOk();

    Notification::assertSentTo($employer, ApplicationWithdrawn::class);
});

test('accepting an application notifies the cleaner', function () {
    Notification::fake();
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id, 'user_id' => $cleaner->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/accept", [])->assertOk();

    Notification::assertSentTo($cleaner, ApplicationAccepted::class);
});

test('rejecting an application notifies the cleaner', function () {
    Notification::fake();
    $employer = User::factory()->employer()->create();
    $cleaner = User::factory()->cleaner()->create();
    $post = CleaningJobPost::factory()->create(['employer_id' => $employer->id]);
    $application = Application::factory()->create(['cleaning_job_post_id' => $post->id, 'user_id' => $cleaner->id]);
    Sanctum::actingAs($employer);

    $this->patchJson("/api/v1/applications/{$application->id}/reject", [])->assertOk();

    Notification::assertSentTo($cleaner, ApplicationRejected::class);
});

test('the reminder command notifies cleaners of accepted jobs scheduled tomorrow only', function () {
    Notification::fake();
    $tomorrowPost = CleaningJobPost::factory()->create(['schedule_date' => now()->addDay()->toDateString()]);
    $nextWeekPost = CleaningJobPost::factory()->create(['schedule_date' => now()->addWeek()->toDateString()]);
    $dueTomorrow = Application::factory()->status(ApplicationStatus::Accepted)->create(['cleaning_job_post_id' => $tomorrowPost->id]);
    $dueNextWeek = Application::factory()->status(ApplicationStatus::Accepted)->create(['cleaning_job_post_id' => $nextWeekPost->id]);
    $pendingTomorrow = Application::factory()->status(ApplicationStatus::Pending)->create(['cleaning_job_post_id' => $tomorrowPost->id]);

    $this->artisan('app:send-job-reminders')->assertSuccessful();

    Notification::assertSentTo($dueTomorrow->user, JobReminder::class);
    Notification::assertNotSentTo($dueNextWeek->user, JobReminder::class);
    Notification::assertNotSentTo($pendingTomorrow->user, JobReminder::class);
});

test('the reminder command does not double-send on a second run', function () {
    $post = CleaningJobPost::factory()->create(['schedule_date' => now()->addDay()->toDateString()]);
    $application = Application::factory()->status(ApplicationStatus::Accepted)->create(['cleaning_job_post_id' => $post->id]);

    $this->artisan('app:send-job-reminders');
    $this->artisan('app:send-job-reminders');

    expect($application->user->notifications()->where('type', JobReminder::class)->count())->toBe(1);
});

test("a user's notification list only contains their own, newest first", function () {
    $cleaner = User::factory()->cleaner()->create();
    $other = User::factory()->cleaner()->create();
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    $other->notify(new JobReminder(Application::factory()->create(['user_id' => $other->id])));
    Sanctum::actingAs($cleaner);

    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data');
});

test('unread_only narrows the list to notifications not yet read', function () {
    $cleaner = User::factory()->cleaner()->create();
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    $cleaner->unreadNotifications()->first()->markAsRead();
    Sanctum::actingAs($cleaner);

    $this->getJson('/api/v1/notifications?unread_only=1')->assertOk()->assertJsonCount(1, 'data');
});

test("a user can mark their own notification as read but not another user's", function () {
    $cleaner = User::factory()->cleaner()->create();
    $other = User::factory()->cleaner()->create();
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    $notification = $cleaner->notifications()->sole();
    Sanctum::actingAs($cleaner);

    $this->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('id', $notification->id);
    expect($notification->fresh()->read_at)->not->toBeNull();

    Sanctum::actingAs($other);
    $this->patchJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
});

test('mark-all-read clears every unread notification for the authenticated user', function () {
    $cleaner = User::factory()->cleaner()->create();
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    $cleaner->notify(new JobReminder(Application::factory()->create(['user_id' => $cleaner->id])));
    Sanctum::actingAs($cleaner);

    $this->patchJson('/api/v1/notifications/read-all')->assertOk();

    expect($cleaner->unreadNotifications()->count())->toBe(0);
});

test('a guest cannot read or manage notifications', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->patchJson('/api/v1/notifications/read-all')->assertUnauthorized();
});
