<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreModeratorRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The single place moderator accounts are created and revoked, admin-only —
 * the counterpart to registration excluding moderators from self-signup.
 * Revoking is a soft delete, so a removed moderator is recoverable and never
 * hard-deleted.
 */
class ModeratorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $moderators = User::query()
            ->withTrashed()
            ->where('role', UserRole::Moderator)
            ->latest()
            ->get();

        return AdminUserResource::collection($moderators);
    }

    public function store(StoreModeratorRequest $request): AdminUserResource
    {
        $moderator = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'role' => UserRole::Moderator,
        ]);
        // An admin-provisioned account skips email verification; forceFill
        // because email_verified_at is intentionally not mass-assignable.
        $moderator->forceFill(['email_verified_at' => now()])->save();

        AuditLog::record($request->user(), 'moderator.created', $moderator);

        return new AdminUserResource($moderator);
    }

    /**
     * Revoke a moderator — a soft delete plus token revocation, so they lose
     * access immediately but the account can be restored. Only moderator rows
     * are revokable here; anything else is a 422.
     */
    public function destroy(Request $request, int $id): AdminUserResource
    {
        $moderator = User::withTrashed()->findOrFail($id);

        abort_unless($moderator->isModerator(), 422, 'Only moderator accounts can be revoked here.');

        $moderator->tokens()->delete();
        $moderator->delete();

        AuditLog::record($request->user(), 'moderator.revoked', $moderator);

        return new AdminUserResource($moderator);
    }
}
