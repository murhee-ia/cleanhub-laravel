<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRatingRequest;
use App\Http\Resources\RatingResource;
use App\Models\Application;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RatingController extends Controller
{
    /**
     * Rate the other party of a job, once the reviewer's own side is completed.
     * Each side unlocks independently:
     *   - Cleaner rating the employer: their application must be `completed`
     *   - Employer rating the cleaner: the job post must be `completed`
     *
     * The policy enforces this per-role; the controller only handles the
     * duplicate-review guard and the reviewee derivation.
     */
    public function store(StoreRatingRequest $request): RatingResource
    {
        $application = Application::with('cleaningJobPost')->findOrFail($request->integer('application_id'));

        Gate::authorize('review', [Rating::class, $application]);

        $reviewerId = $request->user()->id;
        $isCleaner = $request->user()->isCleaner();

        // Role-aware completion guard (policy already enforces this, but an
        // explicit check here produces a clearer 422 message for API clients).
        if ($isCleaner && $application->status !== ApplicationStatus::Completed) {
            throw ValidationException::withMessages([
                'application_id' => 'You can only rate the employer after marking your application as complete.',
            ]);
        }

        if (! $isCleaner && $application->cleaningJobPost->status !== JobPostStatus::Completed) {
            throw ValidationException::withMessages([
                'application_id' => 'You can only rate a cleaner after marking the job post as completed.',
            ]);
        }

        $revieweeId = $isCleaner
            ? $application->cleaningJobPost->employer_id
            : $application->user_id;

        $alreadyReviewed = Rating::query()
            ->where('application_id', $application->id)
            ->where('reviewer_id', $reviewerId)
            ->exists();

        if ($alreadyReviewed) {
            throw ValidationException::withMessages([
                'application_id' => 'You have already rated this job.',
            ]);
        }

        $rating = Rating::create([
            'application_id' => $application->id,
            'reviewer_id'    => $reviewerId,
            'reviewee_id'    => $revieweeId,
            'stars'          => $request->validated('stars'),
            'text'           => $request->validated('text'),
        ]);

        $rating->load(['reviewer', 'reviewee', 'application.cleaningJobPost']);

        return new RatingResource($rating);
    }

    /**
     * A cleaner's public reviews, written by the employers of their completed
     * jobs.
     */
    public function forCleaner(Request $request, int $id): AnonymousResourceCollection
    {
        $user = User::where('role', UserRole::Cleaner)->findOrFail($id);

        return $this->listFor($request, $user);
    }

    /**
     * An employer's public reviews, written by the cleaners of their completed
     * jobs.
     */
    public function forEmployer(Request $request, int $id): AnonymousResourceCollection
    {
        $user = User::where('role', UserRole::Employer)->findOrFail($id);

        return $this->listFor($request, $user);
    }

    protected function listFor(Request $request, User $reviewee): AnonymousResourceCollection
    {
        $validated = $request->validate(['per_page' => $this->perPageRule()]);

        $ratings = Rating::query()
            ->where('reviewee_id', $reviewee->id)
            ->visible()
            ->with(['reviewer', 'reviewee', 'application.cleaningJobPost'])
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return RatingResource::collection($ratings);
    }
}
