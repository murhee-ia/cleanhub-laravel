<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavedJobRequest;
use App\Http\Resources\SavedJobResource;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
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

        $this->attachViewerFlags($saved->getCollection(), $request->user());

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

        $this->attachViewerFlags(new Collection([$savedJob]), $request->user());

        return (new SavedJobResource($savedJob))->response()->setStatusCode(201);
    }

    /**
     * The embedded job posts are loaded through the saved row, so the viewer
     * flags CleaningJobPostResource reads are not set by a query scope here.
     * Every job in this list is saved by the viewer by definition; applied
     * state costs one extra lookup for the whole page rather than one per row.
     *
     * @param  Collection<int, SavedJob>  $savedJobs
     */
    protected function attachViewerFlags(Collection $savedJobs, User $viewer): void
    {
        $applicationStatuses = Application::query()
            ->where('user_id', $viewer->id)
            ->whereIn('cleaning_job_post_id', $savedJobs->pluck('cleaning_job_post_id'))
            ->pluck('status', 'cleaning_job_post_id')
            ->map(fn (ApplicationStatus $status): string => $status->value);

        $savedJobs->each(function (SavedJob $savedJob) use ($applicationStatuses): void {
            $savedJob->cleaningJobPost
                ->setAttribute('is_saved_by_viewer', true)
                ->setAttribute('has_applied_by_viewer', $applicationStatuses->has($savedJob->cleaning_job_post_id))
                ->setAttribute('viewer_application_status_raw', $applicationStatuses->get($savedJob->cleaning_job_post_id));
        });
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
