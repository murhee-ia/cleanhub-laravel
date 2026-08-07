<?php

namespace App\Http\Resources;

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
            'created_at' => $this->created_at,
        ];
    }
}
