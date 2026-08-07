<?php

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CleaningJobCategoryController;
use App\Http\Controllers\Api\V1\CleaningJobPostController;
use App\Http\Controllers\Api\V1\JobApplicantController;
use App\Http\Controllers\Api\V1\Moderation\ReportModerationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\RatingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SavedJobController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [RegisterController::class, 'store']);
        Route::post('login', [LoginController::class, 'store']);
        Route::post('forgot-password', [PasswordResetLinkController::class, 'store']);
        Route::post('reset-password', [NewPasswordController::class, 'store']);
        Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware('signed')
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [LoginController::class, 'destroy']);
            Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store']);
        });
    });

    Route::get('cleaning-job-categories', [CleaningJobCategoryController::class, 'index']);

    Route::get('cleaning-job-posts', [CleaningJobPostController::class, 'index']);
    Route::get('cleaning-job-posts/{id}', [CleaningJobPostController::class, 'show'])
        ->whereNumber('id');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);

        Route::get('cleaners/{id}', [PublicProfileController::class, 'cleaner']);
        Route::get('employers/{id}', [PublicProfileController::class, 'employer']);
        Route::get('employers/{id}/cleaning-job-posts', [CleaningJobPostController::class, 'forEmployer'])
            ->whereNumber('id');
        Route::get('cleaners/{id}/ratings', [RatingController::class, 'forCleaner'])
            ->whereNumber('id');
        Route::get('employers/{id}/ratings', [RatingController::class, 'forEmployer'])
            ->whereNumber('id');
        Route::post('ratings', [RatingController::class, 'store']);

        Route::get('cleaning-job-posts/mine', [CleaningJobPostController::class, 'mine']);
        Route::post('cleaning-job-posts', [CleaningJobPostController::class, 'store']);
        Route::patch('cleaning-job-posts/{cleaningJobPost}', [CleaningJobPostController::class, 'update'])
            ->whereNumber('cleaningJobPost');
        Route::delete('cleaning-job-posts/{cleaningJobPost}', [CleaningJobPostController::class, 'destroy'])
            ->whereNumber('cleaningJobPost');

        Route::get('saved-jobs', [SavedJobController::class, 'index']);
        Route::post('saved-jobs', [SavedJobController::class, 'store']);
        Route::delete('saved-jobs/{cleaningJobPost}', [SavedJobController::class, 'destroy'])
            ->whereNumber('cleaningJobPost');

        Route::get('applications', [ApplicationController::class, 'index']);
        Route::post('applications', [ApplicationController::class, 'store']);
        Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])
            ->whereNumber('application');

        Route::get('calendar', [ApplicationController::class, 'calendar']);

        // `/detail` keeps the single-application read from colliding with the
        // flat GET /applications collection above.
        Route::get('cleaning-job-posts/{cleaningJobPost}/applications', [JobApplicantController::class, 'index'])
            ->whereNumber('cleaningJobPost');
        Route::get('applications/{application}/detail', [JobApplicantController::class, 'show'])
            ->whereNumber('application');
        Route::patch('applications/{application}/accept', [JobApplicantController::class, 'accept'])
            ->whereNumber('application');
        Route::patch('applications/{application}/reject', [JobApplicantController::class, 'reject'])
            ->whereNumber('application');
        Route::patch('applications/{application}/note', [JobApplicantController::class, 'note'])
            ->whereNumber('application');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Filing a report is open to any authenticated user; working the queue
        // it feeds is not — that lives behind the moderator/admin group below.
        Route::post('reports', [ReportController::class, 'store']);

        Route::prefix('moderation')->middleware('role:moderator,admin')->group(function (): void {
            Route::get('reports', [ReportModerationController::class, 'index']);
            Route::get('reports/{report}', [ReportModerationController::class, 'show'])
                ->whereNumber('report');
            Route::patch('reports/{report}/resolve', [ReportModerationController::class, 'resolve'])
                ->whereNumber('report');
            Route::patch('reports/{report}/reject', [ReportModerationController::class, 'reject'])
                ->whereNumber('report');
            Route::patch('reports/{report}/escalate', [ReportModerationController::class, 'escalate'])
                ->whereNumber('report');
            Route::patch('reports/{report}/hide', [ReportModerationController::class, 'hide'])
                ->whereNumber('report');
            Route::patch('reports/{report}/warn', [ReportModerationController::class, 'warn'])
                ->whereNumber('report');
        });
    });

    Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());
});
