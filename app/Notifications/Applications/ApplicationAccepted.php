<?php

namespace App\Notifications\Applications;

class ApplicationAccepted extends ApplicationNotification
{
    protected function type(): string
    {
        return 'application_accepted';
    }

    protected function subject(): string
    {
        return 'Your application was accepted';
    }

    protected function message(): string
    {
        return sprintf(
            'Your application for "%s" was accepted.',
            $this->application->cleaningJobPost->title,
        );
    }
}
