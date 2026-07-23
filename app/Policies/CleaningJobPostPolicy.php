<?php

namespace App\Policies;

use App\Models\CleaningJobPost;
use App\Models\User;

/**
 * The admin bypasses every check via the Gate::before hook in
 * AppServiceProvider. Job posts are authored and managed only by the employer
 * who owns them.
 */
class CleaningJobPostPolicy
{
    public function create(User $user): bool
    {
        return $user->isEmployer();
    }

    public function update(User $user, CleaningJobPost $cleaningJobPost): bool
    {
        return $user->isEmployer() && $user->id === $cleaningJobPost->employer_id;
    }

    /**
     * Job posts are deleted by an admin only (through the Gate::before
     * superuser hook). Employers cannot delete their own posts — deny everyone
     * here so the admin bypass is the sole path.
     */
    public function delete(User $user, CleaningJobPost $cleaningJobPost): bool
    {
        return false;
    }
}
