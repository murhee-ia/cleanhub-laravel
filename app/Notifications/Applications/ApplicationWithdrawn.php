<?php

namespace App\Notifications\Applications;

class ApplicationWithdrawn extends ApplicationNotification
{
    protected function type(): string
    {
        return 'application_withdrawn';
    }

    protected function subject(): string
    {
        return 'An applicant withdrew';
    }

    protected function message(): string
    {
        return sprintf(
            'Someone withdrew their application for "%s".',
            $this->application->cleaningJobPost->title,
        );
    }
}
