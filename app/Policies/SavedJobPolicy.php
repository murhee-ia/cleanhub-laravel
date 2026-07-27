<?php

namespace App\Policies;

use App\Models\User;

/**
 * Saving jobs is a cleaner-only feature. The admin bypasses every check via the
 * Gate::before hook in AppServiceProvider; employers and moderators are denied.
 * Ownership of a saved row is enforced in the controller by scoping every query
 * to the authenticated user, so there is no cross-user access to guard here.
 */
class SavedJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCleaner();
    }

    public function create(User $user): bool
    {
        return $user->isCleaner();
    }
}
