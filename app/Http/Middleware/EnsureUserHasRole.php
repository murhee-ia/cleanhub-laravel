<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse role gate for whole route groups — the moderation queue is
 * moderator-or-admin, the admin panel is admin-only. Ownership-level checks
 * (does this employer own this post?) still live in policies; this only asks
 * "does the caller hold one of these roles at all?". Runs after auth:sanctum,
 * so an unauthenticated request is already a 401 before it reaches here.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null || ! in_array($user->role->value, $roles, true), 403);

        return $next($request);
    }
}
