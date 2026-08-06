<?php

namespace App\Policies;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CleaningJobPost;
use App\Models\User;

/**
 * The admin bypasses every check via the Gate::before hook in
 * AppServiceProvider. Applying is a cleaner-only feature; deciding on an
 * application belongs to the employer who owns the job post.
 */
class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCleaner();
    }

    /**
     * Applying is gated on the cleaner role alone. This is also what enforces
     * "an employer cannot apply to their own job": roles are fixed and
     * single-valued, so the employer who owns a post can never hold the cleaner
     * role and never passes this check — an ownership special-case here would
     * be unreachable.
     */
    public function create(User $user): bool
    {
        return $user->isCleaner();
    }

    /**
     * Both sides of an application may read it: the cleaner who submitted it
     * and the employer who owns the job post.
     */
    public function view(User $user, Application $application): bool
    {
        return $user->id === $application->user_id
            || $user->id === $application->cleaningJobPost->employer_id;
    }

    /**
     * Only the applying cleaner may withdraw, and only while the employer has
     * not decided yet.
     */
    public function withdraw(User $user, Application $application): bool
    {
        return $user->id === $application->user_id
            && $application->status === ApplicationStatus::Pending;
    }

    /**
     * Whether the user may see the applicant list of a job post. Invoked as
     * Gate::authorize('viewApplicants', [Application::class, $post]) because the
     * ability lives with applications but is checked against the job post; the
     * leading class string is what routes the check to this policy rather than
     * CleaningJobPostPolicy.
     */
    public function viewApplicants(User $user, CleaningJobPost $cleaningJobPost): bool
    {
        return $user->id === $cleaningJobPost->employer_id;
    }

    /**
     * Accept, reject, and private notes all belong to the employer who owns the
     * job post the application targets.
     */
    public function decide(User $user, Application $application): bool
    {
        return $user->id === $application->cleaningJobPost->employer_id;
    }
}
