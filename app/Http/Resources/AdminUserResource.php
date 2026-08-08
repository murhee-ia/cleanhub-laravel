<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin's fuller view of a user — adds the lifecycle state (suspended,
 * soft-deleted) the public-facing UserResource deliberately omits, so the
 * management table can show who is active, paused, or removed.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'email_verified_at' => $this->email_verified_at,
            'suspended_at' => $this->suspended_at,
            'deleted_at' => $this->deleted_at,
            'is_suspended' => $this->suspended_at !== null,
            'is_deleted' => $this->deleted_at !== null,
            'created_at' => $this->created_at,
        ];
    }
}
