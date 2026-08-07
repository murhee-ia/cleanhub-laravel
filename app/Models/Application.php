<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\RatingStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cleaning_job_post_id
 * @property int $user_id
 * @property ApplicationStatus $status
 * @property string|null $message
 * @property string|null $resume_path
 * @property string|null $private_note
 * @property string|null $decision_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read CleaningJobPost $cleaningJobPost
 */
#[Fillable([
    'cleaning_job_post_id',
    'user_id',
    'message',
    'status',
    'decision_message',
])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    /**
     * Mirror the database column default so a new instance has the correct
     * status before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CleaningJobPost, $this>
     */
    public function cleaningJobPost(): BelongsTo
    {
        return $this->belongsTo(CleaningJobPost::class);
    }

    /**
     * Expose the applying cleaner's rating average/count as
     * `cleaner_rating_average`/`cleaner_rating_count` via a single correlated
     * subquery per list, the same single-subquery-per-list approach as
     * CleaningJobPost's viewer-flag scopes — needed here because
     * JobApplicantController::index renders one ApplicationResource per row.
     *
     * @param  Builder<Application>  $query
     */
    public function scopeWithCleanerRating(Builder $query): void
    {
        $query->addSelect([
            'cleaner_rating_average' => Rating::query()
                ->selectRaw('avg(stars)')
                ->whereColumn('reviewee_id', 'applications.user_id')
                ->where('status', RatingStatus::Visible),
            'cleaner_rating_count' => Rating::query()
                ->selectRaw('count(*)')
                ->whereColumn('reviewee_id', 'applications.user_id')
                ->where('status', RatingStatus::Visible),
        ]);
    }

    /**
     * Expose whether the given viewer has already rated this application as
     * `viewer_has_rated`, the same single-subquery-per-list shape as
     * CleaningJobPost's viewer-flag scopes — this is what the frontend uses to
     * hide the rate button once a review is already in, without needing a
     * second round trip per row.
     *
     * @param  Builder<Application>  $query
     */
    public function scopeWithViewerHasRated(Builder $query, User $viewer): void
    {
        $query->addSelect([
            'viewer_has_rated' => Rating::query()
                ->selectRaw('count(*) > 0')
                ->whereColumn('application_id', 'applications.id')
                ->where('reviewer_id', $viewer->id),
        ]);
    }
}
