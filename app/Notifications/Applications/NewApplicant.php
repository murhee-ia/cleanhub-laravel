<?php

namespace App\Notifications\Applications;

class NewApplicant extends ApplicationNotification
{
    protected function type(): string
    {
        return 'new_applicant';
    }

    protected function subject(): string
    {
        return 'New applicant for your job post';
    }

    protected function message(): string
    {
        return sprintf(
            '%s applied to "%s".',
            $this->application->user->name,
            $this->application->cleaningJobPost->title,
        );
    }
}
