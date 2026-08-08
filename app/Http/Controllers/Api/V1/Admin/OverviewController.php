<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * A single aggregate the admin dashboard's overview cards read from — the
 * headline counts across the platform in one request, so the frontend doesn't
 * fan out to every list endpoint just to show totals.
 */
class OverviewController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'users' => [
                'total' => User::query()->count(),
                'cleaners' => User::query()->where('role', UserRole::Cleaner)->count(),
                'employers' => User::query()->where('role', UserRole::Employer)->count(),
                'moderators' => User::query()->where('role', UserRole::Moderator)->count(),
                'suspended' => User::query()->whereNotNull('suspended_at')->count(),
            ],
            'jobs' => CleaningJobPost::query()->count(),
            'applications' => Application::query()->count(),
            'reports' => [
                'total' => Report::query()->count(),
                'open' => Report::query()->where('status', ReportStatus::Open)->count(),
            ],
            'ratings' => Rating::query()->count(),
        ]);
    }
}
