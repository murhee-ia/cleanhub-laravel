<?php

namespace App\Enums;

enum UserRole: string
{
    case Cleaner = 'cleaner';
    case Employer = 'employer';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /**
     * Roles a user may choose when self-registering. Moderator and admin
     * accounts are provisioned by an admin, never through registration.
     *
     * @return array<int, self>
     */
    public static function selfRegisterable(): array
    {
        return [self::Cleaner, self::Employer];
    }
}
