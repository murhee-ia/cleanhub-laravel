<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only viewer over the append-only audit trail every moderator/admin
 * action writes. Filterable by action and by actor so the admin can trace one
 * kind of action or one person's history.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'max:255'],
            'actor_id' => ['sometimes', 'integer'],
            'per_page' => $this->perPageRule(),
        ]);

        $logs = AuditLog::query()
            ->with('actor')
            ->when(
                isset($validated['action']),
                fn (Builder $query) => $query->where('action', 'like', "%{$validated['action']}%"),
            )
            ->when(
                isset($validated['actor_id']),
                fn (Builder $query) => $query->where('user_id', $validated['actor_id']),
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return AuditLogResource::collection($logs);
    }
}
