<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\SavedJob;
use App\Models\User;
use App\Notifications\Applications\ApplicationWithdrawn;
use App\Notifications\Applications\NewApplicant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ApplicationController extends Controller
{
    /**
     * List the authenticated cleaner's own applications, newest first,
     * paginated, optionally narrowed to one status tab. Each row embeds the full
     * job post with its live status, so an application to a job that has since
     * closed is still returned for the frontend to flag.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Application::class);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(ApplicationStatus::class)],
            'per_page' => $this->perPageRule(),
        ]);

        $applications = Application::query()
            ->where('user_id', $request->user()->id)
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->with(['cleaningJobPost.employer', 'cleaningJobPost.category'])
            ->withViewerHasRated($request->user())
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        $this->attachViewerFlags($applications->getCollection(), $request->user());

        return ApplicationResource::collection($applications);
    }

    /**
     * The authenticated cleaner's accepted/completed applications, unpaginated,
     * for the calendar view. Accepting an application
     * is the only calendar trigger — there is no separate calendar
     * table, this is just a scoped read of the same applications table sorted
     * into schedule order.
     */
    public function calendar(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Application::class);

        $applications = Application::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Completed])
            ->with(['cleaningJobPost.employer', 'cleaningJobPost.category'])
            ->withViewerHasRated($request->user())
            ->get()
            ->sortBy(fn (Application $application): string => $application->cleaningJobPost->schedule_date->toDateString())
            ->values();

        $this->attachViewerFlags($applications, $request->user());

        return ApplicationResource::collection($applications);
    }

    /**
     * Apply to an open, published job as the authenticated cleaner. The unique
     * (cleaning_job_post_id, user_id) index is the permanent guard against a
     * second application; the duplicate check here turns that into a readable
     * 422 instead of a database error. Both rejections report on
     * cleaning_job_post_id and differ only by message, matching
     * SavedJobController::store(). A schedule conflict with an already-accepted
     * job is reported separately as a 409, "warn client-side, validate server-side"
     * overlap rule — a distinct status code (not just a distinct message)
     * so the frontend can tell it apart from a plain validation failure.
     */
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $post = CleaningJobPost::findOrFail($request->integer('cleaning_job_post_id'));

        if ($post->visibility !== JobPostVisibility::Published || $post->status !== JobPostStatus::Open) {
            throw ValidationException::withMessages([
                'cleaning_job_post_id' => 'This job is no longer accepting applications.',
            ]);
        }

        $alreadyApplied = Application::query()
            ->where('user_id', $request->user()->id)
            ->where('cleaning_job_post_id', $post->id)
            ->exists();

        if ($alreadyApplied) {
            throw ValidationException::withMessages([
                'cleaning_job_post_id' => 'You have already applied to this job.',
            ]);
        }

        if ($this->conflictsWithAcceptedSchedule($post, $request->user())) {
            $message = "This job's schedule conflicts with a job you're already accepted for.";

            return response()->json([
                'message' => $message,
                'errors' => ['cleaning_job_post_id' => [$message]],
            ], 409);
        }

        $application = new Application([
            'cleaning_job_post_id' => $post->id,
            'user_id' => $request->user()->id,
            'message' => $request->input('message'),
        ]);

        if ($request->hasFile('resume')) {
            $application->resume_path = $this->storeResume($request->file('resume'));
        }

        $application->save();
        $application->load(['cleaningJobPost.employer', 'cleaningJobPost.category']);

        $this->attachViewerFlags(new Collection([$application]), $request->user());

        $post->employer->notify(new NewApplicant($application));

        return (new ApplicationResource($application))->response()->setStatusCode(201);
    }

    /**
     * Withdraw a pending application. This is a status transition, not a delete:
     * the row must survive so the unique constraint keeps blocking a re-apply.
     */
    public function destroy(Application $application): JsonResponse
    {
        Gate::authorize('withdraw', $application);

        $application->update(['status' => ApplicationStatus::Withdrawn]);

        $application->load('cleaningJobPost.employer');
        $application->cleaningJobPost->employer->notify(new ApplicationWithdrawn($application));

        return response()->json(['message' => 'Application withdrawn.']);
    }

    /**
     * The cleaner marks their side of the job complete by uploading a proof file
     * (photo or PDF). This is independent of the employer marking the job post
     * complete — either side may complete their side first, and rating unlocks
     * for each reviewer once *their own* side is done. Requires the application
     * to be in the `accepted` state (enforced by the policy).
     */
    public function complete(Request $request, Application $application): ApplicationResource
    {
        Gate::authorize('complete', $application);

        $request->validate([
            'proof' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'])->max(10 * 1024),
            ],
        ], [
            'proof.required' => 'A proof file (photo or PDF) is required to mark the job as complete.',
        ]);

        $application->completion_proof_path = $this->storeProof($request->file('proof'));
        $application->status = ApplicationStatus::Completed;
        $application->save();

        $application->load(['cleaningJobPost.employer', 'cleaningJobPost.category']);
        $this->attachViewerFlags(new Collection([$application]), $request->user());

        return new ApplicationResource($application);
    }

    /**
     * The embedded job posts are loaded through the application, so the viewer
     * flags CleaningJobPostResource reads are not set by a query scope here.
     * Every job in this list is applied to by the viewer by definition; saved
     * state costs one extra lookup for the whole page rather than one per row.
     *
     * @param  Collection<int, Application>  $applications
     */
    protected function attachViewerFlags(Collection $applications, User $viewer): void
    {
        $savedPostIds = SavedJob::query()
            ->where('user_id', $viewer->id)
            ->whereIn('cleaning_job_post_id', $applications->pluck('cleaning_job_post_id'))
            ->pluck('cleaning_job_post_id')
            ->all();

        $applications->each(function (Application $application) use ($savedPostIds): void {
            $application->cleaningJobPost
                ->setAttribute('is_saved_by_viewer', in_array($application->cleaning_job_post_id, $savedPostIds, true))
                ->setAttribute('has_applied_by_viewer', true)
                ->setAttribute('viewer_application_status_raw', $application->status->value);
        });
    }

    /**
     * True when the target job's schedule overlaps one of the cleaner's
     * already-accepted jobs on the same date. A missing start/end time on
     * either side means that job isn't scoped to a sub-day window, so it's
     * treated as occupying the whole day.
     */
    protected function conflictsWithAcceptedSchedule(CleaningJobPost $target, User $cleaner): bool
    {
        $acceptedJobIds = Application::query()
            ->where('user_id', $cleaner->id)
            ->where('status', ApplicationStatus::Accepted)
            ->pluck('cleaning_job_post_id');

        $sameDayAccepted = CleaningJobPost::query()
            ->whereIn('id', $acceptedJobIds)
            ->whereDate('schedule_date', $target->schedule_date)
            ->get();

        foreach ($sameDayAccepted as $existing) {
            if ($this->timeWindowsOverlap($existing, $target)) {
                return true;
            }
        }

        return false;
    }

    protected function timeWindowsOverlap(CleaningJobPost $existingJob, CleaningJobPost $targetJob): bool
    {
        if (
            $existingJob->start_time === null ||
            $existingJob->end_time === null ||
            $targetJob->start_time === null ||
            $targetJob->end_time === null
        ) {
            return true;
        }

        return $existingJob->start_time < $targetJob->end_time &&
                $targetJob->start_time < $existingJob->end_time;
    }

    /**
     * Store an uploaded proof file (photo or PDF) on the public disk.
     */
    protected function storeProof(UploadedFile $file): string
    {
        $path = $file->store('application-proofs', 'public');

        if ($path === false) {
            throw new RuntimeException('Failed to store uploaded proof file.');
        }

        return $path;
    }

    /**
     * Store an uploaded resume on the public disk, failing fast if the write
     * does not succeed.
     */
    protected function storeResume(UploadedFile $file): string
    {
        $path = $file->store('application-resumes', 'public');

        if ($path === false) {
            throw new RuntimeException('Failed to store uploaded resume.');
        }

        return $path;
    }
}
