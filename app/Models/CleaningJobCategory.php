<?php

namespace App\Models;

use Database\Factories\CleaningJobCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CleanerProfile> $cleanerProfiles
 */
#[Fillable(['name', 'slug', 'is_active'])]
class CleaningJobCategory extends Model
{
    /** @use HasFactory<CleaningJobCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<CleanerProfile, $this>
     */
    public function cleanerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(CleanerProfile::class);
    }
}
