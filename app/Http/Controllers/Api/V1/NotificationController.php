<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /**
     * The authenticated user's own notifications, newest first, paginated.
     * `unread_only` powers both the bell's badge count and its dropdown list
     * with the same endpoint, just a different query string.
     */
    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $notifications = $request->user()->notifications()
            ->when(
                $validated['unread_only'] ?? false,
                fn (Builder $query) => $query->whereNull('read_at'),
            )
            ->paginate($validated['per_page'] ?? 50);

        return NotificationResource::collection($notifications);
    }

    /**
     * Mark one of the authenticated user's own notifications as read. Scoped
     * through their own notifications() relation rather than a global route
     * model bind, so requesting someone else's notification id 404s instead
     * of leaking whether it exists.
     */
    public function markRead(Request $request, string $notification): NotificationResource
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return new NotificationResource($record);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
