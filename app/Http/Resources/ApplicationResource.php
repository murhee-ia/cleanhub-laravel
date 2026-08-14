<?php

namespace App\Http\Resources;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostStatus;
use App\Models\Application;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Application
 */
class ApplicationResource extends JsonResource
{
    /**
     * The cleaner's own list embeds the job it targets; the employer's applicant
     * list embeds a summary of the cleaner instead, plus the private note only
     * the employer can see.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'message' => $this->message,
            'resume_url' => $this->resume_path === null ? null : Storage::disk('public')->url($this->resume_path),
            // The cleaner's completion proof URL — visible to both the cleaner and the employer.
            'completion_proof_url' => $this->completion_proof_path === null ? null : Storage::disk('public')->url($this->completion_proof_path),
            'job' => $this->when(
                $viewer?->isCleaner() === true,
                fn (): CleaningJobPostResource => new CleaningJobPostResource($this->cleaningJobPost),
            ),
            // Indicates whether the employer has also marked the job post as completed.
            'job_completed' => $this->cleaningJobPost->status === JobPostStatus::Completed,
            'cleaner' => $this->when(
                $viewer?->isEmployer() === true,
                fn (): array => $this->cleanerSummary(),
            ),
            // The employer writes this one *for* the cleaner, so unlike
            // private_note it is readable by both sides.
            'decision_message' => $this->decision_message,
            'private_note' => $this->when($viewer?->isEmployer() === true, fn (): ?string => $this->private_note),
            // Rating unlock is per-side:
            //   - Cleaner: shown when their application is completed (they can rate the employer)
            //   - Employer: shown when the job post is completed (they can rate the cleaner)
            'viewer_has_rated' => $this->when(
                $this->viewerCanRate($viewer),
                fn (): bool => $this->viewerHasRated($viewer),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Whether the current viewer has unlocked their side for rating.
     * Cleaners unlock when their application is completed; employers when the job post is.
     */
    protected function viewerCanRate(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isCleaner()) {
            return $this->status === ApplicationStatus::Completed;
        }

        // Employer side: job post must be completed.
        return $this->cleaningJobPost->status === JobPostStatus::Completed;
    }

    protected function viewerHasRated(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return array_key_exists('viewer_has_rated', $this->resource->getAttributes())
            ? (bool) $this->getAttribute('viewer_has_rated')
            : Rating::query()
                ->where('application_id', $this->id)
                ->where('reviewer_id', $viewer->id)
                ->exists();
    }

    /**
     * completed_jobs_count stays a placeholder; rating fields read from
     * JobApplicantController::index's single-subquery-per-page select when
     * present, and fall back to a direct query on a single-record response
     * (show/accept/reject/note), matching CleaningJobPostResource's employer
     * block.
     *
     * @return array{id: int, full_name: string, photo_url: string|null, rating_average: float|null, rating_count: int, completed_jobs_count: int}
     */
    protected function cleanerSummary(): array
    {
        $photoPath = $this->user->cleanerProfile?->photo_path;

        return [
            'id' => $this->user->id,
            'full_name' => $this->user->name,
            'photo_url' => $photoPath === null ? null : Storage::disk('public')->url($photoPath),
            'rating_average' => $this->cleanerRatingAverage(),
            'rating_count' => $this->cleanerRatingCount(),
            'completed_jobs_count' => 0,
        ];
    }

    protected function cleanerRatingAverage(): ?float
    {
        $average = array_key_exists('cleaner_rating_average', $this->resource->getAttributes())
            ? $this->getAttribute('cleaner_rating_average')
            : $this->user->ratingsReceived()->visible()->avg('stars');

        return $average === null ? null : round((float) $average, 2);
    }

    protected function cleanerRatingCount(): int
    {
        return array_key_exists('cleaner_rating_count', $this->resource->getAttributes())
            ? (int) $this->getAttribute('cleaner_rating_count')
            : $this->user->ratingsReceived()->visible()->count();
    }
}
