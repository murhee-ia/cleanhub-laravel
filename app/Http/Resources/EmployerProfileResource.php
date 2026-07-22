<?php

namespace App\Http\Resources;

use App\Models\EmployerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin EmployerProfile
 */
class EmployerProfileResource extends JsonResource
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
            'employer_type' => $this->employer_type,
            'contact_person_name' => $this->contact_person_name,
            'contact_person_contact' => $this->contact_person_contact,
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->address,
            'about' => $this->about,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'documents' => $this->mapDocuments(),
            'rating_average' => null,
            'rating_count' => 0,
            'posted_jobs_count' => 0,
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
