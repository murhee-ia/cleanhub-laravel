<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\Auth\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $suspended_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read CleanerProfile|null $cleanerProfile
 * @property-read EmployerProfile|null $employerProfile
 */
#[Fillable(['name', 'email', 'role', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isCleaner(): bool
    {
        return $this->role === UserRole::Cleaner;
    }

    public function isEmployer(): bool
    {
        return $this->role === UserRole::Employer;
    }

    public function isModerator(): bool
    {
        return $this->role === UserRole::Moderator;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    /**
     * @return HasOne<CleanerProfile, $this>
     */
    public function cleanerProfile(): HasOne
    {
        return $this->hasOne(CleanerProfile::class);
    }

    /**
     * @return HasOne<EmployerProfile, $this>
     */
    public function employerProfile(): HasOne
    {
        return $this->hasOne(EmployerProfile::class);
    }

    /**
     * Job posts created by this user (employer role).
     *
     * @return HasMany<CleaningJobPost, $this>
     */
    public function jobPosts(): HasMany
    {
        return $this->hasMany(CleaningJobPost::class, 'employer_id');
    }

    /**
     * Applications submitted by this user (cleaner role).
     *
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Ratings this user received, either as the cleaner or as the employer of
     * a completed application — the two directions are independent rows on
     * the same table, distinguished only by who the reviewee is.
     *
     * @return HasMany<Rating, $this>
     */
    public function ratingsReceived(): HasMany
    {
        return $this->hasMany(Rating::class, 'reviewee_id');
    }

    /**
     * @return HasMany<Rating, $this>
     */
    public function ratingsGiven(): HasMany
    {
        return $this->hasMany(Rating::class, 'reviewer_id');
    }
}
