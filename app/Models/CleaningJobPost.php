<?php

namespace App\Models;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use Database\Factories\CleaningJobPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
