<?php

namespace App\Models;

use Database\Factories\EmployerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $employer_type
 * @property string|null $contact_person_name
 * @property string|null $contact_person_contact
 * @property string|null $country
 * @property string|null $city
 * @property string|null $address
 * @property string|null $about
 * @property string|null $photo_path
 * @property array<int, string>|null $documents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'employer_type',
    'contact_person_name',
    'contact_person_contact',
    'country',
    'city',
    'address',
    'about',
    'photo_path',
    'documents',
])]
class EmployerProfile extends Model
{
    /** @use HasFactory<EmployerProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
