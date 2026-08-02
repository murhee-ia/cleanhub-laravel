<?php

namespace App\Http\Resources;

use App\Models\Application;
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
            'job' => $this->when(
                $viewer?->isCleaner() === true,
                fn (): CleaningJobPostResource => new CleaningJobPostResource($this->cleaningJobPost),
            ),
            'cleaner' => $this->when(
                $viewer?->isEmployer() === true,
                fn (): array => $this->cleanerSummary(),
            ),
            // The employer writes this one *for* the cleaner, so unlike
            // private_note it is readable by both sides.
            'decision_message' => $this->decision_message,
            'private_note' => $this->when($viewer?->isEmployer() === true, fn (): ?string => $this->private_note),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Rating and completed-job counts are placeholders until Phase 7 lands the
     * rating system, matching CleanerProfileResource's own stubs.
     *
     * @return array{id: int, full_name: string, photo_url: string|null, rating_average: null, rating_count: int, completed_jobs_count: int}
     */
    protected function cleanerSummary(): array
    {
        $photoPath = $this->user->cleanerProfile?->photo_path;

        return [
            'id' => $this->user->id,
            'full_name' => $this->user->name,
            'photo_url' => $photoPath === null ? null : Storage::disk('public')->url($photoPath),
            'rating_average' => null,
            'rating_count' => 0,
            'completed_jobs_count' => 0,
        ];
    }
}
