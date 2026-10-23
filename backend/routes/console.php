<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\Reminders\ReminderGenerationService;
use App\Services\Escalations\WeeklyEscalationService;
use App\Support\SystemTimezone;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// -----------------------------------------------------------------------------
// --- Reminder generation jobs ------------------------------------------------
// -----------------------------------------------------------------------------

//FOR TESTING PURPOSES ONLY

    //command to generate weekly gathering reminders for secretaries
    Artisan::command('reminders:generate-weekly-gatherings', function () {
        $created = app(ReminderGenerationService::class)->generateWeeklyGatheringReminders();

        $this->info("Created {$created} weekly gathering reminder(s).");
    })->purpose('Create the next PM, WS, and TG reminders for secretaries');

    //command to delete expired gathering and submission reminders
    Artisan::command('reminders:delete-expired', function () {
        $deleted = app(ReminderGenerationService::class)->deleteExpiredReminders();

        $this->info("Deleted {$deleted} expired reminder(s).");
    })->purpose('Delete expired gathering and submission reminders');

    Artisan::command('reminders:expire-completed', function () {
        $expired = app(ReminderGenerationService::class)->expireCompletedReminders();

        $this->info("Marked {$expired} completed reminder(s) as expired.");
    })->purpose('Expire completed reminders');

    Artisan::command('escalations:refresh-weekly', function () {
        $synced = app(WeeklyEscalationService::class)->syncAll();

        $this->info("Refreshed weekly escalation for {$synced} student(s).");
    })->purpose('Refresh student escalation levels from this week’s attendance');


//FOR SCHEDULING

$schedulerTimezone = SystemTimezone::timezone();

    //weekly gathering reminders for secretaries
    Schedule::command('reminders:generate-weekly-gatherings')
        ->weeklyOn(Carbon::MONDAY, '00:05')
        ->timezone($schedulerTimezone)
        ->withoutOverlapping();

    // Reset escalation counts to the current week's PM, WS, and TG responses.
    Schedule::command('escalations:refresh-weekly')
        ->weeklyOn(Carbon::WEDNESDAY, '00:00')
        ->timezone($schedulerTimezone)
        ->withoutOverlapping();

    //delete expired gathering and submission reminders
    Schedule::command('reminders:delete-expired')
        ->dailyAt('00:15')
        ->timezone($schedulerTimezone)
        ->withoutOverlapping();

    // Mark completed reminders as expired every Monday at 11:59 PM; retain their rows for history.
    Schedule::command('reminders:expire-completed')
        ->weeklyOn(Carbon::MONDAY, '23:59')
        ->timezone($schedulerTimezone)
        ->withoutOverlapping();

    //spawn submission reminders for secretaries
    Schedule::call(function () {
        app(ReminderGenerationService::class)->spawnSubmissionReminders();
    })
        ->name('spawn-submission-reminders')
        ->daily()
        ->timezone($schedulerTimezone)
        ->withoutOverlapping();

    //refresh submission progress for secretaries
    //this is for updating the progress of submission reminders, so that the countdown text is accurate
    Schedule::call(function () {
        app(ReminderGenerationService::class)->refreshSubmissionProgress();
    })
        ->name('refresh-submission-progress')
        ->hourly()
        ->timezone($schedulerTimezone)
        ->withoutOverlapping();
