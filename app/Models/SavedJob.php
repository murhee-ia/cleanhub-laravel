<?php

namespace App\Models;

use Database\Factories\SavedJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $cleaning_job_post_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read CleaningJobPost $cleaningJobPost
 */
#[Fillable(['user_id', 'cleaning_job_post_id'])]
class SavedJob extends Model
{
    /** @use HasFactory<SavedJobFactory> */
    use HasFactory;

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
