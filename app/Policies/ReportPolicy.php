<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * The admin bypasses every check via the Gate::before hook in
 * AppServiceProvider, so these methods only ever describe the moderator's
 * reach. Reporting itself is open to any signed-in user.
 */
class ReportPolicy
{
    /**
     * Anyone with an account can file a report — the reporter is any
     * authenticated user, per the moderation spec.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Working the report queue (list, detail, and every handling action) is a
     * moderator responsibility. Instance-level checks aren't needed: a report
     * isn't "owned" the way a job post is — any moderator may act on any one.
     */
    public function viewAny(User $user): bool
    {
        return $user->isModerator();
    }

    public function view(User $user, Report $report): bool
    {
        return $user->isModerator();
    }

    public function handle(User $user, Report $report): bool
    {
        return $user->isModerator();
    }
}
