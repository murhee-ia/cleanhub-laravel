<?php

namespace App\Notifications\Applications;

class JobReminder extends ApplicationNotification
{
    protected function type(): string
    {
        return 'job_reminder';
    }

    protected function subject(): string
    {
        return 'Upcoming job tomorrow';
    }

    protected function message(): string
    {
        return sprintf(
            '"%s" is scheduled for tomorrow, %s.',
            $this->application->cleaningJobPost->title,
            $this->application->cleaningJobPost->schedule_date->toFormattedDateString(),
        );
    }
}
