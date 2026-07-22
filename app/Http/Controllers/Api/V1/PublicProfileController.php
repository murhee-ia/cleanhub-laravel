<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\CleanerProfileResource;
use App\Http\Resources\EmployerProfileResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PublicProfileController extends Controller
{
    /**
     * Show a cleaner's public profile by user id. Any authenticated user may
     * view it; the owner-only email is excluded for everyone but the owner.
     */
    public function cleaner(int $id): CleanerProfileResource
    {
        $user = User::where('role', UserRole::Cleaner)->findOrFail($id);

        $profile = $user->cleanerProfile()->with('cleaningJobCategories')->first()
            ?? $user->cleanerProfile()->make()->setRelation('cleaningJobCategories', new Collection);

        $profile->setRelation('user', $user);

        return new CleanerProfileResource($profile);
    }

    /**
     * Show an employer's public profile by user id.
     */
    public function employer(int $id): EmployerProfileResource
    {
        $user = User::where('role', UserRole::Employer)->findOrFail($id);

        $profile = $user->employerProfile()->first()
            ?? $user->employerProfile()->make();

        $profile->setRelation('user', $user);

        return new EmployerProfileResource($profile);
    }
}
