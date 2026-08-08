<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admin user management. Every mutating action is reversible — suspend pairs
 * with reactivate, delete is a soft delete that restore brings back — and each
 * writes an audit entry. The admin account itself is off-limits here: there is
 * only one, and it must never be suspended, deleted, or re-roled.
 */
class UserController extends Controller
{
    /**
     * All users including suspended and soft-deleted ones, searchable by name
     * or email and filterable by role and lifecycle status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'deleted'])],
            'per_page' => $this->perPageRule(),
        ]);

        $users = User::query()
            ->withTrashed()
            ->when(
                isset($validated['search']),
                fn (Builder $query) => $query->where(function (Builder $inner) use ($validated): void {
                    $inner->where('name', 'like', "%{$validated['search']}%")
                        ->orWhere('email', 'like', "%{$validated['search']}%");
                }),
            )
            ->when(
                isset($validated['role']),
                fn (Builder $query) => $query->where('role', $validated['role']),
            )
            ->when(
                ($validated['status'] ?? null) === 'active',
                fn (Builder $query) => $query->whereNull('deleted_at')->whereNull('suspended_at'),
            )
            ->when(
                ($validated['status'] ?? null) === 'suspended',
                fn (Builder $query) => $query->whereNotNull('suspended_at'),
            )
            ->when(
                ($validated['status'] ?? null) === 'deleted',
                fn (Builder $query) => $query->whereNotNull('deleted_at'),
            )
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return AdminUserResource::collection($users);
    }

    public function show(int $id): AdminUserResource
    {
        return new AdminUserResource(User::withTrashed()->findOrFail($id));
    }

    /**
     * Pause an account. Revokes existing tokens so the block takes effect
     * immediately, not just at the next login attempt.
     */
    public function suspend(Request $request, int $id): AdminUserResource
    {
        $user = $this->resolveManageableUser($id);

        $user->forceFill(['suspended_at' => now()])->save();
        $user->tokens()->delete();

        AuditLog::record($request->user(), 'user.suspended', $user);

        return new AdminUserResource($user);
    }

    public function reactivate(Request $request, int $id): AdminUserResource
    {
        $user = $this->resolveManageableUser($id);

        $user->forceFill(['suspended_at' => null])->save();

        AuditLog::record($request->user(), 'user.reactivated', $user);

        return new AdminUserResource($user);
    }

    public function changeRole(UpdateUserRoleRequest $request, int $id): AdminUserResource
    {
        $user = $this->resolveManageableUser($id);
        $previousRole = $user->role->value;

        $user->update(['role' => $request->validated('role')]);

        AuditLog::record($request->user(), 'user.role_changed', $user, [
            'from' => $previousRole,
            'to' => $user->role->value,
        ]);

        return new AdminUserResource($user);
    }

    /**
     * Soft delete — the row survives and drops out of normal queries, and
     * restore brings it back. Tokens are revoked so a deletion logs the user
     * out at once.
     */
    public function destroy(Request $request, int $id): AdminUserResource
    {
        $user = $this->resolveManageableUser($id);

        $user->tokens()->delete();
        $user->delete();

        AuditLog::record($request->user(), 'user.deleted', $user);

        return new AdminUserResource($user);
    }

    public function restore(Request $request, int $id): AdminUserResource
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        AuditLog::record($request->user(), 'user.restored', $user);

        return new AdminUserResource($user);
    }

    /**
     * Load a user (including trashed) and refuse to touch the admin account —
     * the one row that management actions must never reach.
     */
    protected function resolveManageableUser(int $id): User
    {
        $user = User::withTrashed()->findOrFail($id);

        abort_if($user->isAdmin(), 403, 'The admin account cannot be modified.');

        return $user;
    }
}
