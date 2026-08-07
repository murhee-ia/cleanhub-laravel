<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Every notification's `data` array already carries `type` and `message`
     * (see ApplicationNotification::toArray()) plus whichever resource ids
     * that event relates to — flattened here rather than nested, since a
     * client parses this the same way regardless of which event it is.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...$this->data,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
