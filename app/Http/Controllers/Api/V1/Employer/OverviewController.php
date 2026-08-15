<?php

namespace App\Http\Controllers\Api\V1\Employer;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employer\IndexOverviewRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Employer-scoped management, tracking, and analysis data in one request.
 * Keeping these aggregates together avoids making the dashboard fan out to
 * every job, application, rating, and notification endpoint independently.
 */
class OverviewController extends Controller
{
    public function show(IndexOverviewRequest $request): JsonResponse
    {
        /** @var User $employer */
        $employer = $request->user();
        $range = $request->validated('range', '30d');
        $periodStart = $this->periodStart($range);
        $today = CarbonImmutable::today();

        $jobPipeline = $this->jobPipeline($employer);
        $applicantPipeline = $this->applicantPipeline($employer);
        $unratedCleaners = $this->unratedCleanersCount($employer);

        $priorityJobs = CleaningJobPost::query()
            ->where('employer_id', $employer->id)
            ->whereNotIn('status', [JobPostStatus::Completed, JobPostStatus::Removed])
            ->with('category:id,name')
            ->withCount([
                'applications',
                'applications as pending_applications_count' => fn (Builder $query) => $query
                    ->where('status', ApplicationStatus::Pending),
                'applications as accepted_applications_count' => fn (Builder $query) => $query
                    ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed]),
            ])
            ->orderByDesc('pending_applications_count')
            ->orderByRaw('case when application_deadline is null then 1 else 0 end')
            ->orderBy('application_deadline')
            ->limit(5)
            ->get();

        $upcomingJobs = CleaningJobPost::query()
            ->where('employer_id', $employer->id)
            ->published()
            ->whereDate('schedule_date', '>=', $today)
            ->whereHas('applications', fn (Builder $query) => $query
                ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed]))
            ->with('category:id,name')
            ->withCount(['applications as accepted_applications_count' => fn (Builder $query) => $query
                ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed])])
            ->orderBy('schedule_date')
            ->limit(5)
            ->get();

        $upcomingJobsCount = CleaningJobPost::query()
            ->where('employer_id', $employer->id)
            ->published()
            ->whereDate('schedule_date', '>=', $today)
            ->whereHas('applications', fn (Builder $query) => $query
                ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed]))
            ->count();

        $recentApplications = Application::query()
            ->whereHas('cleaningJobPost', fn (Builder $query) => $query
                ->where('employer_id', $employer->id))
            ->with([
                'user:id,name',
                'cleaningJobPost:id,title,schedule_date',
            ])
            ->withCleanerRating()
            ->latest()
            ->limit(6)
            ->get();

        $recentNotifications = $employer->notifications()
            ->latest()
            ->limit(5)
            ->get();

        $unreadNotifications = $employer->unreadNotifications()->count();

        return response()->json([
            'summary' => [
                'total_posts' => $jobPipeline['total'],
                'active_posts' => $jobPipeline['open'] + $jobPipeline['reviewing'],
                'pending_applicants' => $applicantPipeline['pending'],
                'accepted_cleaners' => $applicantPipeline['accepted'] + $applicantPipeline['completed'],
                'upcoming_jobs' => $upcomingJobsCount,
                'completed_jobs' => $jobPipeline['completed'],
            ],
            'job_pipeline' => $jobPipeline,
            'applicant_pipeline' => $applicantPipeline,
            'attention' => [
                'draft_posts' => $jobPipeline['draft'],
                'pending_applications' => $applicantPipeline['pending'],
                'closing_soon' => CleaningJobPost::query()
                    ->where('employer_id', $employer->id)
                    ->published()
                    ->whereIn('status', [JobPostStatus::Open, JobPostStatus::Reviewing])
                    ->whereBetween('application_deadline', [$today, $today->addDays(7)])
                    ->count(),
                'awaiting_completion' => CleaningJobPost::query()
                    ->where('employer_id', $employer->id)
                    ->where('status', JobPostStatus::Closed)
                    ->whereDate('schedule_date', '<=', $today)
                    ->count(),
                'unrated_cleaners' => $unratedCleaners,
                'unread_notifications' => $unreadNotifications,
            ],
            'priority_jobs' => $priorityJobs->map(fn (CleaningJobPost $job): array => $this->jobData($job))->values(),
            'upcoming_jobs' => $upcomingJobs->map(fn (CleaningJobPost $job): array => $this->jobData($job))->values(),
            'recent_applications' => $recentApplications->map(function (Application $application): array {
                /** @var float|int|string|null $ratingAverage */
                $ratingAverage = $application->getAttribute('cleaner_rating_average');

                return [
                    'id' => $application->id,
                    'status' => $application->status->value,
                    'created_at' => $application->created_at,
                    'cleaner' => [
                        'id' => $application->user->id,
                        'name' => $application->user->name,
                        'rating_average' => $ratingAverage !== null
                            ? round((float) $ratingAverage, 1)
                            : null,
                        'rating_count' => (int) $application->getAttribute('cleaner_rating_count'),
                    ],
                    'job' => [
                        'id' => $application->cleaningJobPost->id,
                        'title' => $application->cleaningJobPost->title,
                        'schedule_date' => $application->cleaningJobPost->schedule_date->toDateString(),
                    ],
                ];
            })->values(),
            'performance' => $this->performance($employer, $range, $periodStart),
            'reputation' => [
                'average_rating' => $this->averageRating($employer),
                'rating_count' => Rating::query()->visible()->where('reviewee_id', $employer->id)->count(),
                'completed_relationships' => Application::query()
                    ->whereHas('cleaningJobPost', fn (Builder $query) => $query
                        ->where('employer_id', $employer->id)
                        ->where('status', JobPostStatus::Completed))
                    ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed])
                    ->count(),
                'ratings_to_give' => $unratedCleaners,
            ],
            'notifications' => [
                'unread_count' => $unreadNotifications,
                'items' => NotificationResource::collection($recentNotifications)->resolve($request),
            ],
        ]);
    }

    /**
     * @return array{total: int, draft: int, open: int, reviewing: int, closed: int, completed: int, removed: int}
     */
    private function jobPipeline(User $employer): array
    {
        $counts = CleaningJobPost::query()
            ->where('employer_id', $employer->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when visibility = ? then 1 else 0 end) as draft', [JobPostVisibility::Draft->value])
            ->selectRaw('sum(case when visibility = ? and status = ? then 1 else 0 end) as open', [JobPostVisibility::Published->value, JobPostStatus::Open->value])
            ->selectRaw('sum(case when visibility = ? and status = ? then 1 else 0 end) as reviewing', [JobPostVisibility::Published->value, JobPostStatus::Reviewing->value])
            ->selectRaw('sum(case when visibility = ? and status = ? then 1 else 0 end) as closed', [JobPostVisibility::Published->value, JobPostStatus::Closed->value])
            ->selectRaw('sum(case when visibility = ? and status = ? then 1 else 0 end) as completed', [JobPostVisibility::Published->value, JobPostStatus::Completed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as removed', [JobPostStatus::Removed->value])
            ->firstOrFail();

        return [
            'total' => (int) $counts->getAttribute('total'),
            'draft' => (int) $counts->getAttribute('draft'),
            'open' => (int) $counts->getAttribute('open'),
            'reviewing' => (int) $counts->getAttribute('reviewing'),
            'closed' => (int) $counts->getAttribute('closed'),
            'completed' => (int) $counts->getAttribute('completed'),
            'removed' => (int) $counts->getAttribute('removed'),
        ];
    }

    /**
     * @return array{total: int, pending: int, accepted: int, rejected: int, withdrawn: int, completed: int}
     */
    private function applicantPipeline(User $employer): array
    {
        $counts = Application::query()
            ->whereHas('cleaningJobPost', fn (Builder $query) => $query
                ->where('employer_id', $employer->id))
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as pending', [ApplicationStatus::Pending->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as accepted', [ApplicationStatus::Accepted->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as rejected', [ApplicationStatus::Rejected->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as withdrawn', [ApplicationStatus::Withdrawn->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [ApplicationStatus::Completed->value])
            ->firstOrFail();

        return [
            'total' => (int) $counts->getAttribute('total'),
            'pending' => (int) $counts->getAttribute('pending'),
            'accepted' => (int) $counts->getAttribute('accepted'),
            'rejected' => (int) $counts->getAttribute('rejected'),
            'withdrawn' => (int) $counts->getAttribute('withdrawn'),
            'completed' => (int) $counts->getAttribute('completed'),
        ];
    }

    private function unratedCleanersCount(User $employer): int
    {
        return Application::query()
            ->whereHas('cleaningJobPost', fn (Builder $query) => $query
                ->where('employer_id', $employer->id)
                ->where('status', JobPostStatus::Completed))
            ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed])
            ->whereDoesntHave('ratings', fn (Builder $query) => $query
                ->where('reviewer_id', $employer->id))
            ->count();
    }

    /**
     * @return array<string, int|float|string|array<int, array{label: string, value: int}>>
     */
    private function performance(User $employer, string $range, ?CarbonImmutable $periodStart): array
    {
        $posts = CleaningJobPost::query()->where('employer_id', $employer->id);
        $applications = Application::query()->whereHas('cleaningJobPost', fn (Builder $query) => $query
            ->where('employer_id', $employer->id));

        if ($periodStart !== null) {
            $posts->where('created_at', '>=', $periodStart);
            $applications->where('created_at', '>=', $periodStart);
        }

        $postsCreated = (clone $posts)->count();
        $applicationsReceived = (clone $applications)->count();
        $acceptedApplicants = (clone $applications)
            ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed])
            ->count();
        $completedJobs = (clone $posts)->where('status', JobPostStatus::Completed)->count();
        $filledPosts = (clone $posts)
            ->whereHas('applications', fn (Builder $query) => $query
                ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed]))
            ->count();

        return [
            'range' => $range,
            'posts_created' => $postsCreated,
            'applications_received' => $applicationsReceived,
            'accepted_applicants' => $acceptedApplicants,
            'completed_jobs' => $completedJobs,
            'average_applicants_per_post' => $postsCreated > 0
                ? round($applicationsReceived / $postsCreated, 1)
                : 0,
            'fill_rate' => $postsCreated > 0 ? round(($filledPosts / $postsCreated) * 100, 1) : 0,
            'completion_rate' => $postsCreated > 0 ? round(($completedJobs / $postsCreated) * 100, 1) : 0,
            'application_trend' => $this->applicationTrend($employer, $range, $periodStart),
        ];
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function applicationTrend(User $employer, string $range, ?CarbonImmutable $periodStart): array
    {
        $monthly = $range === 'all';
        $driver = DB::getDriverName();
        $bucket = match (true) {
            $driver === 'sqlite' && $monthly => "strftime('%Y-%m', applications.created_at)",
            $driver === 'sqlite' => "strftime('%Y-%m-%d', applications.created_at)",
            $monthly => "date_format(applications.created_at, '%Y-%m')",
            default => 'date(applications.created_at)',
        };

        $query = Application::query()
            ->join('cleaning_job_posts', 'cleaning_job_posts.id', '=', 'applications.cleaning_job_post_id')
            ->where('cleaning_job_posts.employer_id', $employer->id)
            ->whereNull('cleaning_job_posts.deleted_at')
            ->selectRaw("{$bucket} as period, count(*) as total")
            ->groupBy(DB::raw($bucket))
            ->orderBy('period');

        if ($periodStart !== null) {
            $query->where('applications.created_at', '>=', $periodStart);
        }

        /** @var Collection<string, int> $counts */
        $counts = $query->get()->mapWithKeys(fn (Application $row): array => [
            (string) $row->getAttribute('period') => (int) $row->getAttribute('total'),
        ]);

        if ($monthly) {
            return $counts->map(fn (int $value, string $label): array => compact('label', 'value'))->values()->all();
        }

        $start = $periodStart ?? CarbonImmutable::today();
        $trend = [];

        for ($date = $start; $date->lte(CarbonImmutable::today()); $date = $date->addDay()) {
            $label = $date->toDateString();
            $trend[] = ['label' => $label, 'value' => $counts->get($label, 0)];
        }

        return $trend;
    }

    private function periodStart(string $range): ?CarbonImmutable
    {
        return match ($range) {
            '7d' => CarbonImmutable::today()->subDays(6),
            '30d' => CarbonImmutable::today()->subDays(29),
            '90d' => CarbonImmutable::today()->subDays(89),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function jobData(CleaningJobPost $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'category' => $job->category->name,
            'city' => $job->city,
            'country' => $job->country,
            'schedule_date' => $job->schedule_date->toDateString(),
            'application_deadline' => $job->application_deadline?->toDateString(),
            'visibility' => $job->visibility->value,
            'status' => $job->status->value,
            'cleaners_needed' => $job->cleaners_needed,
            'applications_count' => (int) ($job->applications_count ?? 0),
            'pending_applications_count' => (int) ($job->pending_applications_count ?? 0),
            'accepted_applications_count' => (int) ($job->accepted_applications_count ?? 0),
        ];
    }

    private function averageRating(User $employer): ?float
    {
        $average = Rating::query()->visible()->where('reviewee_id', $employer->id)->avg('stars');

        return $average !== null ? round((float) $average, 1) : null;
    }
}
