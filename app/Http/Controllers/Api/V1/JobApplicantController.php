<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideApplicationRequest;
use App\Http\Requests\UpdateApplicationNoteRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\CleaningJobPost;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class JobApplicantController extends Controller
{
    /**
     * List the applicants of one of the authenticated employer's job posts.
     * The ability lives on ApplicationPolicy but is checked against the job
     * post, so the leading class string is what routes the check there instead
     * of to CleaningJobPostPolicy.
     */
    public function index(CleaningJobPost $cleaningJobPost): AnonymousResourceCollection
    {
        Gate::authorize('viewApplicants', [Application::class, $cleaningJobPost]);

        $applications = Application::query()
            ->where('cleaning_job_post_id', $cleaningJobPost->id)
            ->where('status', '!=', ApplicationStatus::Withdrawn)
            ->with(['user.cleanerProfile'])
            ->latest()
            ->paginate(15);

        // Applicants who withdrew drop out of the list the employer works
        // through, but the employer still gets to see how many there were.
        $withdrawnCount = Application::query()
            ->where('cleaning_job_post_id', $cleaningJobPost->id)
            ->where('status', ApplicationStatus::Withdrawn)
            ->count();

        return ApplicationResource::collection($applications)
            ->additional(['meta' => ['withdrawn_count' => $withdrawnCount]]);
    }

    /**
     * Show a single application. Readable by both sides: the cleaner who
     * submitted it and the employer who owns the job post.
     */
    public function show(Application $application): ApplicationResource
    {
        Gate::authorize('view', $application);

        $application->load(['user.cleanerProfile', 'cleaningJobPost.employer', 'cleaningJobPost.category']);

        return new ApplicationResource($application);
    }

    /**
     * Accept an applicant. This is the only place that ever sets `accepted`,
     * and accepting is what surfaces the job on the cleaner's calendar.
     */
    public function accept(DecideApplicationRequest $request, Application $application): ApplicationResource
    {
        return $this->decide($request, $application, ApplicationStatus::Accepted);
    }

    /**
     * Reject an applicant.
     */
    public function reject(DecideApplicationRequest $request, Application $application): ApplicationResource
    {
        return $this->decide($request, $application, ApplicationStatus::Rejected);
    }

    /**
     * Set or clear the employer's private note on an applicant. Never visible
     * to the cleaner — ApplicationResource only emits it to employers.
     */
    public function note(UpdateApplicationNoteRequest $request, Application $application): ApplicationResource
    {
        $application->private_note = $request->input('note');
        $application->save();

        $application->load(['user.cleanerProfile']);

        return new ApplicationResource($application);
    }

    /**
     * Move a still-pending application to a decided status, optionally with a
     * message for the cleaner. Anything already decided (or withdrawn by the
     * cleaner) is a 422 rather than a silent overwrite. Authorization lives on
     * DecideApplicationRequest, same as the note endpoint.
     */
    protected function decide(
        DecideApplicationRequest $request,
        Application $application,
        ApplicationStatus $status,
    ): ApplicationResource {
        if ($application->status !== ApplicationStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'This application has already been decided.',
            ]);
        }

        $application->update([
            'status' => $status,
            'decision_message' => $request->validated('message'),
        ]);

        $application->load(['user.cleanerProfile']);

        return new ApplicationResource($application);
    }
}
