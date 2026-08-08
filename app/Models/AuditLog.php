<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of a privileged action (a report resolution, a content
 * hide, a suspension, a role change, ...). Rows are written once and never
 * updated, which is why only `created_at` is tracked.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 * @property-read User|null $actor
 * @property-read Model|null $auditable
 */
#[Fillable([
    'user_id',
    'action',
    'auditable_type',
    'auditable_id',
    'context',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * Write one entry. The single funnel every moderator/admin action calls,
     * so the trail can never be half-recorded: `$actor` is the acting user,
     * `$auditable` the optional subject (null for target-less actions like a
     * settings change), and `$context` a snapshot of what changed.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(User $actor, string $action, ?Model $auditable = null, array $context = []): self
    {
        return self::create([
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'context' => $context ?: null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
