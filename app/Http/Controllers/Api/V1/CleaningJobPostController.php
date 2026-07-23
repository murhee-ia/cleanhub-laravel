<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCleaningJobPostRequest;
use App\Http\Requests\UpdateCleaningJobPostRequest;
use App\Http\Resources\CleaningJobPostResource;
use App\Models\CleaningJobPost;
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
            'sort' => ['sometimes', Rule::in(['newest', 'soonest', 'high_pay', 'top_employer'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $viewer = $request->user('sanctum');
        $canFilterByStatus = $viewer !== null && ! $viewer->isCleaner();

        if (isset($validated['status']) && ! $canFilterByStatus) {
            throw ValidationException::withMessages([
                'status' => 'Filtering job posts by status is not available for this account.',
            ]);
        }

        $query = CleaningJobPost::query()
            ->published()
            ->when(
                isset($validated['status']),
                fn (Builder $q) => $q->where('status', $validated['status']),
                fn (Builder $q) => $q->open(),
            )
            ->with(['employer', 'category'])
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

        $posts = $query->paginate($validated['per_page'] ?? 15)->withQueryString();

        return CleaningJobPostResource::collection($posts);
    }

    /**
     * @param  Builder<CleaningJobPost>  $query
     */
    protected function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'soonest' => $query->orderBy('schedule_date'),
            'high_pay' => $query->orderByDesc('pay_amount'),
            // top_employer sorts by employer rating once Phase 7 lands; until
            // then there is no rating, so it falls back to newest.
            'top_employer' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };
    }

    /**
     * List the authenticated employer's own job posts (every visibility and
     * status) for their dashboard.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $posts = CleaningJobPost::query()
            ->where('employer_id', $request->user()->id)
            ->with(['employer', 'category'])
            ->latest()
            ->paginate(15);

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

        return (new CleaningJobPostResource($post))->response()->setStatusCode(201);
    }

    /**
     * Update a job post owned by the authenticated employer. Only the fields
     * present are changed; uploaded media replaces the existing set.
     */
    public function update(UpdateCleaningJobPostRequest $request, CleaningJobPost $cleaningJobPost): CleaningJobPostResource
    {
        $cleaningJobPost->fill($request->safe()->except('media'));

        if ($request->hasFile('media')) {
            $cleaningJobPost->media = $this->storeMedia($request->file('media'));
        }

        $cleaningJobPost->save();
        $cleaningJobPost->load(['employer', 'category']);

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
        $post = CleaningJobPost::with(['employer', 'category'])->findOrFail($id);

        $viewer = $request->user('sanctum');
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
