<?php

namespace App\Models;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Enums\RatingStatus;
use Database\Factories\CleaningJobPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employer_id
 * @property int $cleaning_job_category_id
 * @property string $title
 * @property string $description
 * @property string|null $requirements
 * @property string|null $qualifications
 * @property string $country
 * @property string $city
 * @property string|null $address
 * @property Carbon $schedule_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property int $cleaners_needed
 * @property Carbon|null $application_deadline
 * @property JobPostVisibility $visibility
 * @property JobPostStatus $status
 * @property string|null $pay_amount
 * @property string|null $pay_currency
 * @property array<int, array{name: string, path: string}>|null $media
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $employer
 * @property-read CleaningJobCategory $category
 */
#[Fillable([
    'cleaning_job_category_id',
    'title',
    'description',
    'requirements',
    'qualifications',
    'country',
    'city',
    'address',
    'schedule_date',
    'start_time',
    'end_time',
    'cleaners_needed',
    'application_deadline',
    'visibility',
    'status',
    'pay_amount',
    'pay_currency',
    'media',
])]
class CleaningJobPost extends Model
{
    /** @use HasFactory<CleaningJobPostFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Mirror the database column defaults so a new instance has correct values
     * before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'draft',
        'status' => 'open',
        'cleaners_needed' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'application_deadline' => 'date',
            'cleaners_needed' => 'integer',
            'visibility' => JobPostVisibility::class,
            'status' => JobPostStatus::class,
            'pay_amount' => 'decimal:2',
            'media' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * @return BelongsTo<CleaningJobCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CleaningJobCategory::class, 'cleaning_job_category_id');
    }

    /**
     * @return HasMany<SavedJob, $this>
     */
    public function savedJobs(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Expose whether the given viewer (a cleaner) has saved each post as the
     * `is_saved_by_viewer` attribute via a single existence subquery, so it
     * costs no extra query per row on list endpoints. A no-op for guests and
     * non-cleaners, who never see the flag.
     *
     * @param  Builder<CleaningJobPost>  $query
     */
    public function scopeWithViewerSaved(Builder $query, ?User $viewer): void
    {
        if ($viewer === null || ! $viewer->isCleaner()) {
            return;
        }

        $query->withExists(['savedJobs as is_saved_by_viewer' => function (Builder $subQuery) use ($viewer): void {
            $subQuery->where('user_id', $viewer->id);
        }]);
    }

    /**
     * Expose whether the given viewer (a cleaner) has applied to each post, and
     * the status of that application, as the `has_applied_by_viewer` and
     * `viewer_application_status_raw` attributes. Same single-subquery approach
     * as scopeWithViewerSaved, and a no-op for guests and non-cleaners. The MAX
     * aggregate is safe because the unique (cleaning_job_post_id, user_id)
     * constraint guarantees at most one matching row.
     *
     * @param  Builder<CleaningJobPost>  $query
     */
    public function scopeWithViewerApplication(Builder $query, ?User $viewer): void
    {
        if ($viewer === null || ! $viewer->isCleaner()) {
            return;
        }

        $constrainToViewer = function (Builder $subQuery) use ($viewer): void {
            $subQuery->where('user_id', $viewer->id);
        };

        $query
            ->withExists(['applications as has_applied_by_viewer' => $constrainToViewer])
            ->withMax(['applications as viewer_application_status_raw' => $constrainToViewer], 'status');
    }

    /**
     * Expose the owning employer's rating average/count as
     * `employer_rating_average`/`employer_rating_count` via a single
     * correlated subquery per list, so a browse feed of many posts costs one
     * extra pair of subqueries total, not one pair per row.
     *
     * @param  Builder<CleaningJobPost>  $query
     */
    public function scopeWithEmployerRating(Builder $query): void
    {
        $query->addSelect([
            'employer_rating_average' => Rating::query()
                ->selectRaw('avg(stars)')
                ->whereColumn('reviewee_id', 'cleaning_job_posts.employer_id')
                ->where('status', RatingStatus::Visible),
            'employer_rating_count' => Rating::query()
                ->selectRaw('count(*)')
                ->whereColumn('reviewee_id', 'cleaning_job_posts.employer_id')
                ->where('status', RatingStatus::Visible),
        ]);
    }

    /**
     * @param  Builder<CleaningJobPost>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('visibility', JobPostVisibility::Published);
    }

    /**
     * @param  Builder<CleaningJobPost>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', JobPostStatus::Open);
    }

    /**
     * @param  Builder<CleaningJobPost>  $query
     */
    public function scopeNotRemoved(Builder $query): void
    {
        $query->where('status', '!=', JobPostStatus::Removed);
    }
}
