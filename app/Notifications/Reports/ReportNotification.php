<?php

namespace App\Notifications\Reports;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every moderation notification (a new report reaching the queue, an
 * escalation reaching the admin, a warning reaching a user) is about one
 * report, rides the same two channels, and stores the same three fields.
 * Only the wording and machine-readable `type` differ per event — the same
 * split ApplicationNotification uses for the application lifecycle.
 */
abstract class ReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Report $report) {}

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
     * @return array{type: string, message: string, report_id: int, reportable_type: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'message' => $this->message(),
            'report_id' => $this->report->id,
            'reportable_type' => $this->report->reportable_type,
        ];
    }

    abstract protected function type(): string;

    abstract protected function subject(): string;

    abstract protected function message(): string;
}
