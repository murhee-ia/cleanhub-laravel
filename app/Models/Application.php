<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
