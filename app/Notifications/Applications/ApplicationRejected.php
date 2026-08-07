<?php

namespace App\Notifications\Applications;

class ApplicationRejected extends ApplicationNotification
{
    protected function type(): string
    {
        return 'application_rejected';
    }

    protected function subject(): string
    {
        return 'An update on your application';
    }

    protected function message(): string
    {
        return sprintf(
            'Your application for "%s" was not accepted this time.',
            $this->application->cleaningJobPost->title,
        );
    }
}
