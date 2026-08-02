<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavedJobRequest;
use App\Http\Resources\SavedJobResource;
use App\Models\CleaningJobPost;
use App\Models\SavedJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SavedJobController extends Controller
{
    /**
     * List the authenticated cleaner's saved jobs, newest first, paginated. Each
     * row embeds the full job post with its live status, so a job that has since
     * closed/filled/completed is still returned (never filtered out) for the
     * frontend to flag.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SavedJob::class);

        $saved = SavedJob::query()
            ->where('user_id', $request->user()->id)
            ->with(['cleaningJobPost.employer', 'cleaningJobPost.category'])
            ->latest()
            ->paginate(15);

        // Every job in the cleaner's own list is saved by them by definition,
        // so set the flag the embedded job resource reads without a subquery.
        $saved->getCollection()->each(
            fn (SavedJob $savedJob) => $savedJob->cleaningJobPost->setAttribute('is_saved_by_viewer', true)
        );

        return SavedJobResource::collection($saved);
    }

    /**
     * Save an open, published job for the authenticated cleaner. The save-time
     * gate requires the target job to currently be open + published; a job may
     * later transition to another status while it stays in the list.
     */
    public function store(StoreSavedJobRequest $request): JsonResponse
    {
        $post = CleaningJobPost::findOrFail($request->integer('cleaning_job_post_id'));

        if ($post->visibility !== JobPostVisibility::Published || $post->status !== JobPostStatus::Open) {
            throw ValidationException::withMessages([
                'cleaning_job_post_id' => 'Only open, published jobs can be saved.',
            ]);
        }

        $alreadySaved = SavedJob::query()
            ->where('user_id', $request->user()->id)
            ->where('cleaning_job_post_id', $post->id)
            ->exists();

        if ($alreadySaved) {
            throw ValidationException::withMessages([
                'cleaning_job_post_id' => 'This job is already in your saved list.',
            ]);
        }

        $savedJob = SavedJob::create([
            'user_id' => $request->user()->id,
            'cleaning_job_post_id' => $post->id,
        ]);

        $savedJob->load(['cleaningJobPost.employer', 'cleaningJobPost.category']);
        $savedJob->cleaningJobPost->setAttribute('is_saved_by_viewer', true);

        return (new SavedJobResource($savedJob))->response()->setStatusCode(201);
    }

    /**
     * Remove a job from the authenticated cleaner's saved list. The route param
     * is the job-post id (not the saved-job id) so the frontend can unsave from
     * a job page without knowing the pivot row id. Scoping the lookup to the
     * current user makes cross-user unsaves a 404, never someone else's row.
     */
    public function destroy(Request $request, CleaningJobPost $cleaningJobPost): JsonResponse
    {
        Gate::authorize('viewAny', SavedJob::class);

        SavedJob::query()
            ->where('user_id', $request->user()->id)
            ->where('cleaning_job_post_id', $cleaningJobPost->id)
            ->firstOrFail()
            ->delete();

        return response()->json(['message' => 'Job removed from saved list.']);
    }
}
