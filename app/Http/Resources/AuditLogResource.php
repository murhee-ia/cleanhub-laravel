<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'context' => $this->context,
            'actor' => $this->when(
                $this->actor !== null,
                fn () => [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                    'role' => $this->actor->role->value,
                ],
            ),
            'created_at' => $this->created_at,
        ];
    }
}
