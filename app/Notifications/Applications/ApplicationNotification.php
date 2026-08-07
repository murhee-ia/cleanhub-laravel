<?php

namespace App\Notifications\Applications;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every application-lifecycle notification (accepted, rejected, a new
 * applicant, a withdrawal, a schedule reminder) shares the same shape: it's
 * about one application, goes out on the same two channels, and stores the
 * same three fields. Only the wording and machine-readable `type` differ per
 * event, so those are the only things a subclass supplies.
 */
abstract class ApplicationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Application $application) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->message());
    }

    /**
     * @return array{type: string, message: string, application_id: int, cleaning_job_post_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'message' => $this->message(),
            'application_id' => $this->application->id,
            'cleaning_job_post_id' => $this->application->cleaning_job_post_id,
        ];
    }

    abstract protected function type(): string;

    abstract protected function subject(): string;

    abstract protected function message(): string;
}
