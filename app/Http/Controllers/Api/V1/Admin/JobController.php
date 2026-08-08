<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\JobPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CleaningJobPostResource;
use App\Models\AuditLog;
use App\Models\CleaningJobPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admin oversight of job posts. Hiding is the same soft toggle a moderator
 * uses on a reported post — the row is never deleted here, just flipped to
 * `removed` and reversible back to `open`.
 */
class JobController extends Controller
{
    /**
     * Every job post regardless of visibility or status, including
     * soft-deleted ones, searchable by title and filterable by status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(JobPostStatus::class)],
            'per_page' => $this->perPageRule(),
        ]);

        $posts = CleaningJobPost::query()
            ->withTrashed()
            ->with(['employer', 'category'])
            ->when(
                isset($validated['search']),
                fn (Builder $query) => $query->where('title', 'like', "%{$validated['search']}%"),
            )
            ->when(
                isset($validated['status']),
                fn (Builder $query) => $query->where('status', $validated['status']),
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return CleaningJobPostResource::collection($posts);
    }

    public function hide(Request $request, CleaningJobPost $cleaningJobPost): CleaningJobPostResource
    {
        $cleaningJobPost->update(['status' => JobPostStatus::Removed]);

        AuditLog::record($request->user(), 'content.hidden', $cleaningJobPost, [
            'reportable_type' => 'job_post',
        ]);

        return new CleaningJobPostResource($cleaningJobPost->load(['employer', 'category']));
    }

    /**
     * Reverse a hide — a removed post returns to `open` so it can be worked
     * again. The counterpart that keeps hiding non-destructive.
     */
    public function unhide(Request $request, CleaningJobPost $cleaningJobPost): CleaningJobPostResource
    {
        $cleaningJobPost->update(['status' => JobPostStatus::Open]);

        AuditLog::record($request->user(), 'content.unhidden', $cleaningJobPost, [
            'reportable_type' => 'job_post',
        ]);

        return new CleaningJobPostResource($cleaningJobPost->load(['employer', 'category']));
    }
}
