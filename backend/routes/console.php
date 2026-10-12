<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\Reminders\ReminderGenerationService;
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


//FOR SCHEDULING

    //weekly gathering reminders for secretaries
    Schedule::command('reminders:generate-weekly-gatherings')
        ->weeklyOn(Carbon::MONDAY, '00:05')
        ->withoutOverlapping();

    //delete expired gathering and submission reminders
    Schedule::command('reminders:delete-expired')
        ->dailyAt('00:15')
        ->withoutOverlapping();

    //spawn submission reminders for secretaries
    Schedule::call(function () {
        app(ReminderGenerationService::class)->spawnSubmissionReminders();
    })->daily();

    //refresh submission progress for secretaries
    //this is for updating the progress of submission reminders, so that the countdown text is accurate
    Schedule::call(function () {
        app(ReminderGenerationService::class)->refreshSubmissionProgress();
    })->hourly();
