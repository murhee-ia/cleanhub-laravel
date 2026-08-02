<?php

namespace App\Http\Resources;

use App\Models\SavedJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedJob
 */
class SavedJobResource extends JsonResource
{
    /**
     * The embedded job carries its live status/visibility, so the frontend can
     * flag a saved job that has since closed/filled without a second request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saved_at' => $this->created_at,
            'job' => new CleaningJobPostResource($this->cleaningJobPost),
        ];
    }
}
