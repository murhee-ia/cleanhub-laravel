<?php

namespace App\Http\Resources;

use App\Models\CleaningJobPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin CleaningJobPost
 */
class CleaningJobPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');
        $isOwner = $viewer !== null && $viewer->id === $this->employer_id;
        $viewerIsCleaner = $viewer !== null && $viewer->isCleaner();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'qualifications' => $this->qualifications,
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ],
            'employer' => [
                'id' => $this->employer->id,
                'name' => $this->employer->name,
                'rating_average' => $this->employerRatingAverage(),
                'rating_count' => $this->employerRatingCount(),
            ],
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->address,
            'schedule_date' => $this->schedule_date->toDateString(),
            'start_time' => $this->formatTime($this->start_time),
            'end_time' => $this->formatTime($this->end_time),
            'cleaners_needed' => $this->cleaners_needed,
            'application_deadline' => $this->application_deadline?->toDateString(),
            'visibility' => $this->visibility->value,
            'status' => $this->status->value,
            'pay_amount' => $this->pay_amount === null ? null : (float) $this->pay_amount,
            'pay_currency' => $this->pay_currency,
            'media' => $this->mapMedia(),
            'applications_count' => $this->when($isOwner, fn (): int => (int) $this->getAttribute('applications_count')),
            'is_saved' => $this->when($viewerIsCleaner, fn (): bool => (bool) $this->getAttribute('is_saved_by_viewer')),
            'has_applied' => $this->when($viewerIsCleaner, fn (): bool => (bool) $this->getAttribute('has_applied_by_viewer')),
            'application_status' => $this->when(
                $viewerIsCleaner && (bool) $this->getAttribute('has_applied_by_viewer'),
                fn (): ?string => $this->getAttribute('viewer_application_status_raw'),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function formatTime(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * List endpoints preload this via CleaningJobPost::withEmployerRating() as
     * a single subquery per page; a freshly created/updated post (store/update)
     * has no such select, so it falls back to a direct one-off query on the
     * relation, which costs nothing extra on those single-record responses.
     */
    protected function employerRatingAverage(): ?float
    {
        $average = array_key_exists('employer_rating_average', $this->resource->getAttributes())
            ? $this->getAttribute('employer_rating_average')
            : $this->employer->ratingsReceived()->visible()->avg('stars');

        return $average === null ? null : round((float) $average, 2);
    }

    protected function employerRatingCount(): int
    {
        return array_key_exists('employer_rating_count', $this->resource->getAttributes())
            ? (int) $this->getAttribute('employer_rating_count')
            : $this->employer->ratingsReceived()->visible()->count();
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    protected function mapMedia(): array
    {
        return collect($this->media ?? [])
            ->map(fn (array $item): array => [
                'name' => $item['name'],
                'url' => Storage::disk('public')->url($item['path']),
            ])
            ->all();
    }
}
