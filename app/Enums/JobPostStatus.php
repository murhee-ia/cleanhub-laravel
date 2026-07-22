<?php

namespace App\Enums;

enum JobPostStatus: string
{
    case Open = 'open';
    case Reviewing = 'reviewing';
    case Closed = 'closed';
    case Removed = 'removed';
    case Completed = 'completed';
}
