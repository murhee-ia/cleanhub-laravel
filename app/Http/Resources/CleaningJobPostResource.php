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
                'rating_average' => null,
                'rating_count' => 0,
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
            'applications_count' => $this->when($isOwner, 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function formatTime(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
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
