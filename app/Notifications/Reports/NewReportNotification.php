<?php

namespace App\Notifications\Reports;

class NewReportNotification extends ReportNotification
{
    protected function type(): string
    {
        return 'new_report';
    }

    protected function subject(): string
    {
        return 'A new report needs review';
    }

    protected function message(): string
    {
        return sprintf('A %s was reported and is waiting in the moderation queue.', str_replace('_', ' ', $this->report->reportable_type));
    }
}
