<?php

namespace App\Notifications\Reports;

class ReportEscalatedNotification extends ReportNotification
{
    protected function type(): string
    {
        return 'report_escalated';
    }

    protected function subject(): string
    {
        return 'A report was escalated to you';
    }

    protected function message(): string
    {
        return sprintf('A moderator escalated a %s report for your decision.', str_replace('_', ' ', $this->report->reportable_type));
    }
}
