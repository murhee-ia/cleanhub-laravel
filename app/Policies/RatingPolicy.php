<?php

namespace App\Policies;

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
     * Only the two parties to an application may rate each other about it —
     * the cleaner who applied and the employer who owns the job post.
     */
    public function review(User $user, Application $application): bool
    {
        return $user->id === $application->user_id
            || $user->id === $application->cleaningJobPost->employer_id;
    }
}
