<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\CleaningJobPostResource;
use App\Models\CleaningJobPost;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CleaningJobPostController extends Controller
{
    /**
     * Show a single job post. Guests and any user may view a published,
     * non-removed post; the owning employer may additionally view their own
     * post in any visibility/status.
     */
    public function show(Request $request, int $id): CleaningJobPostResource
    {
        $post = CleaningJobPost::with(['employer', 'category'])->findOrFail($id);

        $viewer = $request->user('sanctum');
        $isOwner = $viewer !== null && $viewer->id === $post->employer_id;

        $isPubliclyVisible = $post->visibility === JobPostVisibility::Published
            && $post->status !== JobPostStatus::Removed;

        if (! $isOwner && ! $isPubliclyVisible) {
            throw new NotFoundHttpException;
        }

        return new CleaningJobPostResource($post);
    }
}
