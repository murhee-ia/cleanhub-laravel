<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CleaningJobCategoryController;
use App\Http\Controllers\Api\V1\CleaningJobPostController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicProfileController;
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

    Route::get('cleaning-job-posts/{id}', [CleaningJobPostController::class, 'show'])
        ->whereNumber('id');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);

        Route::get('cleaners/{id}', [PublicProfileController::class, 'cleaner']);
        Route::get('employers/{id}', [PublicProfileController::class, 'employer']);
    });

    Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());
});
