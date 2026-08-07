<?php

namespace App\Http\Resources;

use App\Models\CleaningJobCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin's view of a category — unlike the public CleaningJobCategoryResource
 * it exposes `is_active`, since the admin manages retired categories too.
 *
 * @mixin CleaningJobCategory
 */
class AdminCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
        ];
    }
}
