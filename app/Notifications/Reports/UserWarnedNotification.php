<?php

namespace App\Notifications\Reports;

class UserWarnedNotification extends ReportNotification
{
    protected function type(): string
    {
        return 'user_warned';
    }

    protected function subject(): string
    {
        return 'A moderator has issued you a warning';
    }

    protected function message(): string
    {
        $note = $this->report->resolution_note;

        return $note !== null && $note !== ''
            ? sprintf('A moderator reviewed a report and issued a warning: %s', $note)
            : 'A moderator reviewed a report about your activity and issued a warning.';
    }
}
