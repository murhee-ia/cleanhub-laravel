<?php

namespace App\Models;

use App\Enums\RatingStatus;
use Database\Factories\RatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property int $reviewer_id
 * @property int $reviewee_id
 * @property int $stars
 * @property string|null $text
 * @property RatingStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Application $application
 * @property-read User $reviewer
 * @property-read User $reviewee
 */
#[Fillable([
    'application_id',
    'reviewer_id',
    'reviewee_id',
    'stars',
    'text',
    'status',
])]
class Rating extends Model
{
    /** @use HasFactory<RatingFactory> */
    use HasFactory;

    /**
     * Mirror the database column default so a new instance has the correct
     * status before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'visible',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'status' => RatingStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }

    /**
     * @param  Builder<Rating>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', RatingStatus::Visible);
    }
}
