<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reporter_id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property string $reason
 * @property ReportStatus $status
 * @property int|null $handled_by
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $reporter
 * @property-read User|null $handler
 * @property-read Model $reportable
 */
#[Fillable([
    'reporter_id',
    'reportable_type',
    'reportable_id',
    'reason',
    'status',
    'handled_by',
    'resolution_note',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * Mirror the database column default so a new instance reads back the
     * correct status before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * The user who owns the reported thing, and is therefore the one a
     * moderator warns: for a reported user it's that user; for a job post it's
     * the employer who posted it; for a rating it's the reviewer who wrote it.
     * Null if the target no longer exists.
     */
    public function subjectUser(): ?User
    {
        $reportable = $this->reportable;

        return match (true) {
            $reportable instanceof User => $reportable,
            $reportable instanceof CleaningJobPost => $reportable->employer,
            $reportable instanceof Rating => $reportable->reviewer,
            default => null,
        };
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopeWithStatus(Builder $query, ReportStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<Report>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('reportable_type', $type);
    }
}
