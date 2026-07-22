<?php

namespace App\Http\Resources;

use App\Models\CleanerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin CleanerProfile
 */
class CleanerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'full_name' => $this->user->name,
            'email' => $this->when(
                $request->user()?->id === $this->user_id,
                fn () => $this->user->email,
            ),
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'bio' => $this->bio,
            'country' => $this->country,
            'city' => $this->city,
            'cleaning_categories' => CleaningJobCategoryResource::collection(
                $this->whenLoaded('cleaningJobCategories'),
            ),
            'languages' => $this->languages ?? [],
            'documents' => $this->mapDocuments(),
            'rating_average' => null,
            'rating_count' => 0,
            'completed_jobs_count' => 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    protected function mapDocuments(): array
    {
        return collect($this->documents ?? [])
            ->map(fn (array $document): array => [
                'name' => $document['name'],
                'url' => Storage::disk('public')->url($document['path']),
            ])
            ->all();
    }
}
