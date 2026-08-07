<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Notifications\Applications\JobReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('app:send-job-reminders')]
#[Description('Notify cleaners of accepted jobs scheduled for tomorrow')]
class SendJobReminders extends Command
{
    /**
     * Every accepted application whose job is scheduled for tomorrow gets one
     * reminder, checked against the notifications table itself (rather than a
     * separate "sent" flag) so re-running the command the same day is safe.
     */
    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $applications = Application::query()
            ->where('status', ApplicationStatus::Accepted)
            ->whereHas('cleaningJobPost', fn (Builder $query) => $query->whereDate('schedule_date', $tomorrow))
            ->with('cleaningJobPost', 'user')
            ->get();

        $sent = 0;

        foreach ($applications as $application) {
            $alreadySent = $application->user->notifications()
                ->where('type', JobReminder::class)
                ->where('data->application_id', $application->id)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $application->user->notify(new JobReminder($application));
            $sent++;
        }

        $this->info("Sent {$sent} job reminder(s).");

        return self::SUCCESS;
    }
}
