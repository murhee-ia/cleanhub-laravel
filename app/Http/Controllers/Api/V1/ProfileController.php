<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\CleanerProfileResource;
use App\Http\Resources\EmployerProfileResource;
use App\Models\CleanerProfile;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProfileController extends Controller
{
    /**
     * Return the authenticated user's own profile, shaped by their role. The
     * profile row is created on first access so the SPA always has an object
     * to edit.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $resource = match (true) {
            $user->isCleaner() => $this->cleanerProfile($user),
            $user->isEmployer() => $this->employerProfile($user),
            default => throw new NotFoundHttpException('No profile for this account type.'),
        };

        return $resource->response()->setStatusCode(200);
    }

    /**
     * Update the authenticated user's own profile. Multipart request (reached
     * via POST + `_method=PATCH`); only the fields present are changed.
     * Uploaded documents are appended to the existing set.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $resource = $user->isEmployer()
            ? $this->updateEmployerProfile($request, $user)
            : $this->updateCleanerProfile($request, $user);

        return $resource->response()->setStatusCode(200);
    }

    protected function updateCleanerProfile(UpdateProfileRequest $request, User $user): CleanerProfileResource
    {
        $profile = $user->cleanerProfile()->firstOrCreate();

        $profile->fill($request->safe()->only(['bio', 'country', 'city', 'languages']));

        if ($request->hasFile('photo')) {
            $profile->photo_path = $request->file('photo')->store('cleaner-photos', 'public');
        }

        if ($request->hasFile('documents')) {
            $profile->documents = $this->appendDocuments($profile, $request->file('documents'), 'cleaner-documents');
        }

        $profile->save();

        if ($request->has('cleaning_categories')) {
            $profile->cleaningJobCategories()->sync($request->input('cleaning_categories', []));
        }

        $profile->load(['user', 'cleaningJobCategories']);

        return new CleanerProfileResource($profile);
    }

    protected function updateEmployerProfile(UpdateProfileRequest $request, User $user): EmployerProfileResource
    {
        $profile = $user->employerProfile()->firstOrCreate();

        $profile->fill($request->safe()->only([
            'employer_type',
            'contact_person_name',
            'contact_person_contact',
            'country',
            'city',
            'address',
            'about',
        ]));

        if ($request->hasFile('photo')) {
            $profile->photo_path = $request->file('photo')->store('employer-photos', 'public');
        }

        if ($request->hasFile('documents')) {
            $profile->documents = $this->appendDocuments($profile, $request->file('documents'), 'employer-documents');
        }

        $profile->save();
        $profile->load('user');

        return new EmployerProfileResource($profile);
    }

    /**
     * Store newly-uploaded PDFs and append them to the profile's existing
     * document list, preserving each file's original name.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{name: string, path: string}>
     */
    protected function appendDocuments(CleanerProfile|EmployerProfile $profile, array $files, string $directory): array
    {
        $stored = array_map(fn (UploadedFile $file): array => [
            'name' => $file->getClientOriginalName(),
            'path' => $file->store($directory, 'public'),
        ], $files);

        return array_merge($profile->documents ?? [], $stored);
    }

    protected function cleanerProfile(User $user): CleanerProfileResource
    {
        $profile = $user->cleanerProfile()->firstOrCreate();
        $profile->load(['user', 'cleaningJobCategories']);

        return new CleanerProfileResource($profile);
    }

    protected function employerProfile(User $user): EmployerProfileResource
    {
        $profile = $user->employerProfile()->firstOrCreate();
        $profile->load('user');

        return new EmployerProfileResource($profile);
    }
}
