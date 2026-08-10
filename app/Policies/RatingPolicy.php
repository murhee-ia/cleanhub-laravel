<?php

namespace App\Policies;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Models\Application;
use App\Models\User;

/**
 * The admin bypasses every check via the Gate::before hook in
 * AppServiceProvider.
 */
class RatingPolicy
{
    /**
     * Both cleaners and employers end up rating the other side of a completed
     * job, so the ability to create at all is open to either role. Which
     * specific application a rating targets is checked separately by
     * review(), once that application is loaded.
     */
    public function create(User $user): bool
    {
        return $user->isCleaner() || $user->isEmployer();
    }

    /**
     * Only the two parties to an application may rate each other about it.
     * Each side unlocks independently based on their own completion:
     *   - Cleaner → employer: unlocked once the cleaner marks their application complete
     *   - Employer → cleaner: unlocked once the employer marks the job post complete
     *
     * This way neither side can be forced to wait for the other to unlock rating.
     */
    public function review(User $user, Application $application): bool
    {
        $isParty = $user->id === $application->user_id
            || $user->id === $application->cleaningJobPost->employer_id;

        if (! $isParty) {
            return false;
        }

        // Cleaner rating the employer: their own application must be completed.
        if ($user->id === $application->user_id) {
            return $application->status === ApplicationStatus::Completed;
        }

        // Employer rating the cleaner: the job post must be completed.
        return $application->cleaningJobPost->status === JobPostStatus::Completed;
    }
}
