<?php

namespace App\Http\Resources;

use App\Models\CleaningJobPost;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'reportable_type' => $this->reportable_type,
            'reportable_id' => $this->reportable_id,
            // A compact snapshot of the reported thing so the moderator sees
            // what a report is about without a second request. Null if the
            // target was since deleted.
            'target' => $this->targetSummary(),
            'reporter' => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'role' => $this->reporter->role->value,
            ],
            'handled_by' => $this->when(
                $this->handler !== null,
                fn () => [
                    'id' => $this->handler->id,
                    'name' => $this->handler->name,
                ],
            ),
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function targetSummary(): ?array
    {
        $reportable = $this->reportable;

        return match (true) {
            $reportable instanceof User => [
                'label' => $reportable->name,
                'role' => $reportable->role->value,
            ],
            $reportable instanceof CleaningJobPost => [
                'label' => $reportable->title,
                'status' => $reportable->status->value,
            ],
            $reportable instanceof Rating => [
                'label' => $reportable->text,
                'stars' => $reportable->stars,
                'status' => $reportable->status->value,
            ],
            default => null,
        };
    }
}
