<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Escalated = 'escalated';
    case Rejected = 'rejected';

    /**
     * Statuses that still need a moderator's attention — the report is live
     * and unresolved. Used to block a reporter from filing a duplicate while
     * an earlier complaint about the same target is still being worked.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Open, self::UnderReview, self::Escalated];
    }
}
