<?php

namespace App\Http\Resources;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rating
 */
class RatingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->application;
        $jobPost = $application->cleaningJobPost;

        // The reviewer is the one who submitted this rating. Determine whether
        // the *other* party has completed their side:
        //   - If the reviewer is the cleaner (rating the employer), the other
        //     side is the employer's job post completion.
        //   - If the reviewer is the employer (rating the cleaner), the other
        //     side is the cleaner's application completion.
        $reviewerIsTheCleaner = $this->reviewer_id === $application->user_id;

        $otherSideCompleted = $reviewerIsTheCleaner
            ? $jobPost->status === JobPostStatus::Completed
            : $application->status === ApplicationStatus::Completed;

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'stars' => $this->stars,
            'text' => $this->text,
            'reviewer' => [
                'id' => $this->reviewer->id,
                'full_name' => $this->reviewer->name,
                'role' => $this->reviewer->role->value,
            ],
            'reviewee' => [
                'id' => $this->reviewee->id,
                'full_name' => $this->reviewee->name,
                'role' => $this->reviewee->role->value,
            ],
            // Whether the other party in this job has marked their side done.
            // False means the review exists but the job is not yet bilaterally complete.
            'other_side_completed' => $otherSideCompleted,
            // Key job post details displayed alongside each review so the reader
            // has context for what job the review is about.
            'job_post' => [
                'id' => $jobPost->id,
                'title' => $jobPost->title,
                'schedule_date' => $jobPost->schedule_date->toDateString(),
                'city' => $jobPost->city,
                'country' => $jobPost->country,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
