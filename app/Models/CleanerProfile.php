<?php

namespace App\Models;

use Database\Factories\CleanerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $photo_path
 * @property string|null $bio
 * @property string|null $country
 * @property string|null $city
 * @property array<int, string>|null $languages
 * @property array<int, array{name: string, path: string}>|null $documents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, CleaningJobCategory> $cleaningJobCategories
 */
#[Fillable([
    'photo_path',
    'bio',
    'country',
    'city',
    'languages',
    'documents',
])]
class CleanerProfile extends Model
{
    /** @use HasFactory<CleanerProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'documents' => 'array',
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
     * Cleaning categories the cleaner offers, chosen from the admin-managed
     * cleaning_job_categories lookup table.
     *
     * @return BelongsToMany<CleaningJobCategory, $this>
     */
    public function cleaningJobCategories(): BelongsToMany
    {
        return $this->belongsToMany(CleaningJobCategory::class);
    }
}
