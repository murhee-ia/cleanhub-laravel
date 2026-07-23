<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\CleaningJobPostResource;
use App\Models\CleaningJobPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CleaningJobPostController extends Controller
{
    /**
     * Browse published, open job posts. Guest-accessible; supports keyword
     * search, filtering, sorting, and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', Rule::exists('cleaning_job_categories', 'id')],
            'country' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'schedule_date' => ['sometimes', 'date'],
            'sort' => ['sometimes', Rule::in(['newest', 'soonest', 'high_pay', 'top_employer'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = CleaningJobPost::query()
            ->published()
            ->open()
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
}
