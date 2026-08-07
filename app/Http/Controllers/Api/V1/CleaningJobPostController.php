<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCleaningJobPostRequest;
use App\Http\Requests\UpdateCleaningJobPostRequest;
use App\Http\Resources\CleaningJobPostResource;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CleaningJobPostController extends Controller
{
    /**
     * Browse published job posts. Guest-accessible; supports keyword search,
     * filtering, sorting, and pagination. By default only `open` posts are
     * returned; any authenticated non-cleaner (employer/moderator/admin) may
     * filter the whole market by any status, including `removed`. Cleaners and
     * guests cannot filter by status at all, so removed content stays off
     * limits to them.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', Rule::exists('cleaning_job_categories', 'id')],
            'country' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'schedule_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::enum(JobPostStatus::class)],
            'sort' => ['sometimes', Rule::in(['newest', 'soonest', 'top_employer'])],
            'per_page' => $this->perPageRule(),
        ]);

        $viewer = $request->user('sanctum');
        $canFilterByStatus = $viewer !== null && ! $viewer->isCleaner();
        $isCleaner = $viewer !== null && $viewer->isCleaner();

        if (isset($validated['status']) && ! $canFilterByStatus) {
            throw ValidationException::withMessages([
                'status' => 'Filtering job posts by status is not available for this account.',
            ]);
        }

        // A searching cleaner sees published posts of any status except removed,
        // so a keyword can surface reviewing/closed/completed posts; the plain
        // feed and guests stay open-only.
        $searchingCleaner = isset($validated['search'])
            && $viewer !== null
            && $viewer->isCleaner();

        $query = CleaningJobPost::query()
            ->published()
            ->when(
                isset($validated['status']),
                fn (Builder $q) => $q->where('status', $validated['status']),
                fn (Builder $q) => $searchingCleaner ? $q->notRemoved() : $q->open(),
            )
            ->with(['employer', 'category'])
            ->withCount('applications')
            ->withEmployerRating()
            ->withViewerSaved($viewer)
            ->withViewerApplication($viewer)
            // A cleaner has already acted on a job they applied to, so it drops
            // out of their feed. Their applications list is where they track it.
            // A keyword search is exhaustive though: looking a job up by name is
            // a deliberate act, so applied jobs stay findable there.
            ->when(
                $isCleaner && ! isset($validated['search']),
                fn (Builder $q) => $q->whereDoesntHave('applications', fn (Builder $a) => $a->where('user_id', $viewer->id)),
            )
            ->when(
                isset($validated['search']),
                fn (Builder $builder) => $builder->where(function (Builder $inner) use ($validated): void {
                    $term = '%'.$validated['search'].'%';
                    $inner->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhereHas('employer', fn (Builder $e) => $e->where('name', 'like', $term));
                }),
            )
            ->when(isset($validated['category_id']), fn (Builder $q) => $q->where('cleaning_job_category_id', $validated['category_id']))
            ->when(isset($validated['country']), fn (Builder $q) => $q->where('country', $validated['country']))
            ->when(isset($validated['city']), fn (Builder $q) => $q->where('city', $validated['city']))
            ->when(isset($validated['schedule_date']), fn (Builder $q) => $q->whereDate('schedule_date', $validated['schedule_date']));

        $this->applySort($query, $validated['sort'] ?? 'newest');

        $posts = $query->paginate($validated['per_page'] ?? 50)->withQueryString();

        return CleaningJobPostResource::collection($posts);
    }

    /**
     * @param  Builder<CleaningJobPost>  $query
     */
    protected function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'soonest' => $query->orderBy('schedule_date'),
            // Relies on withEmployerRating() already being applied to the query
            // for the `employer_rating_average` select alias to exist; an
            // employer with no ratings yet sorts last, not first, since a NULL
            // average is neither highest nor lowest under most drivers, so it's
            // pinned there explicitly instead of leaving driver behavior to chance.
            'top_employer' => $query
                ->orderByRaw('employer_rating_average IS NULL')
                ->orderByDesc('employer_rating_average'),
            default => $query->orderByDesc('created_at'),
        };
    }

    /**
     * List the authenticated employer's own job posts (every visibility and
     * status) for their dashboard. Supports keyword search, status and schedule
     * filtering, and sorting — all scoped to the employer's own posts, so any
     * status (including `removed`) is filterable here.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(JobPostStatus::class)],
            'schedule_date' => ['sometimes', 'date'],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest', 'soonest'])],
            'per_page' => $this->perPageRule(),
        ]);

        $query = CleaningJobPost::query()
            ->where('employer_id', $request->user()->id)
            ->with(['employer', 'category'])
            ->withCount('applications')
            ->withEmployerRating()
            ->when(
                isset($validated['search']),
                fn (Builder $builder) => $builder->where(function (Builder $inner) use ($validated): void {
                    $term = '%'.$validated['search'].'%';
                    $inner->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term);
                }),
            )
            ->when(isset($validated['status']), fn (Builder $q) => $q->where('status', $validated['status']))
            ->when(isset($validated['schedule_date']), fn (Builder $q) => $q->whereDate('schedule_date', $validated['schedule_date']));

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at'),
            'soonest' => $query->orderBy('schedule_date'),
            default => $query->orderByDesc('created_at'),
        };

        return CleaningJobPostResource::collection($query->paginate($validated['per_page'] ?? 50));
    }

    /**
     * List a given employer's public job posts for their profile page: every
     * published post across open/reviewing/closed/completed. Drafts (not yet
     * public) and removed (hidden) posts are excluded. Any authenticated user
     * may view this, but an employer looking at their own profile is served by
     * mine() instead — so no owner-only data (applications_count) is loaded here.
     */
    public function forEmployer(Request $request, int $id): AnonymousResourceCollection
    {
        $validated = $request->validate(['per_page' => $this->perPageRule()]);

        $employer = User::where('role', UserRole::Employer)->findOrFail($id);

        $posts = CleaningJobPost::query()
            ->where('employer_id', $employer->id)
            ->published()
            ->where('status', '!=', JobPostStatus::Removed->value)
            ->with(['employer', 'category'])
            ->withEmployerRating()
            ->withViewerSaved($request->user('sanctum'))
            ->withViewerApplication($request->user('sanctum'))
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return CleaningJobPostResource::collection($posts);
    }

    /**
     * Create a job post owned by the authenticated employer.
     */
    public function store(StoreCleaningJobPostRequest $request): JsonResponse
    {
        $post = new CleaningJobPost($request->safe()->except('media'));
        $post->employer_id = $request->user()->id;

        if ($request->hasFile('media')) {
            $post->media = $this->storeMedia($request->file('media'));
        }

        $post->save();
        $post->load(['employer', 'category']);
        $post->loadCount('applications');

        return (new CleaningJobPostResource($post))->response()->setStatusCode(201);
    }

    /**
     * Update a job post owned by the authenticated employer. Only the fields
     * present are changed; uploaded media replaces the existing set.
     */
    public function update(UpdateCleaningJobPostRequest $request, CleaningJobPost $cleaningJobPost): CleaningJobPostResource
    {
        $wasCompleted = $cleaningJobPost->status === JobPostStatus::Completed;

        $cleaningJobPost->fill($request->safe()->except('media'));

        if ($request->hasFile('media')) {
            $cleaningJobPost->media = $this->storeMedia($request->file('media'));
        }

        $cleaningJobPost->save();

        // Completing a post is what unlocks rating: every accepted applicant
        // moves to `completed` alongside it, so each side has a completed
        // application to rate the other about. Applications the employer never
        // accepted (rejected/withdrawn) are not part of the completed job and
        // stay as they are.
        if (! $wasCompleted && $cleaningJobPost->status === JobPostStatus::Completed) {
            $cleaningJobPost->applications()
                ->where('status', ApplicationStatus::Accepted)
                ->update(['status' => ApplicationStatus::Completed]);
        }

        $cleaningJobPost->load(['employer', 'category']);
        $cleaningJobPost->loadCount('applications');

        return new CleaningJobPostResource($cleaningJobPost);
    }

    /**
     * Soft-delete a job post owned by the authenticated employer.
     */
    public function destroy(Request $request, CleaningJobPost $cleaningJobPost): JsonResponse
    {
        Gate::authorize('delete', $cleaningJobPost);

        $cleaningJobPost->delete();

        return response()->json(['message' => 'Job post deleted.']);
    }

    /**
     * Show a single job post. Guests and any user may view a published,
     * non-removed post; the owning employer may additionally view their own
     * post in any visibility/status.
     */
    public function show(Request $request, int $id): CleaningJobPostResource
    {
        $viewer = $request->user('sanctum');

        $post = CleaningJobPost::with(['employer', 'category'])
            ->withCount('applications')
            ->withEmployerRating()
            ->withViewerSaved($viewer)
            ->withViewerApplication($viewer)
            ->findOrFail($id);

        $isOwner = $viewer !== null && $viewer->id === $post->employer_id;

        $isPubliclyVisible = $post->visibility === JobPostVisibility::Published
            && $post->status !== JobPostStatus::Removed;

        if (! $isOwner && ! $isPubliclyVisible) {
            throw new NotFoundHttpException;
        }

        return new CleaningJobPostResource($post);
    }

    /**
     * Store uploaded media images on the public disk, preserving each file's
     * original name.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{name: string, path: string}>
     */
    protected function storeMedia(array $files): array
    {
        return array_map(fn (UploadedFile $file): array => [
            'name' => $file->getClientOriginalName(),
            'path' => $this->storeOrFail($file),
        ], $files);
    }

    /**
     * Store an uploaded file on the public disk, failing fast if the write
     * does not succeed.
     */
    protected function storeOrFail(UploadedFile $file): string
    {
        $path = $file->store('job-media', 'public');

        if ($path === false) {
            throw new RuntimeException('Failed to store uploaded media.');
        }

        return $path;
    }
}
