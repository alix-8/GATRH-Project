<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\ReminderGenerationService;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// --- Reminder generation jobs ---

Artisan::command('reminders:generate-weekly-gatherings', function () {
    $created = app(ReminderGenerationService::class)->generateWeeklyGatheringReminders();

    $this->info("Created {$created} weekly gathering reminder(s).");
})->purpose('Create the next PM, WS, and TG reminders for secretaries');

Schedule::command('reminders:generate-weekly-gatherings')
    ->weeklyOn(Carbon::MONDAY, '00:05')
    ->withoutOverlapping();

Schedule::call(function () {
    app(ReminderGenerationService::class)->spawnSubmissionReminders();
})->daily();

Schedule::call(function () {
    app(ReminderGenerationService::class)->refreshSubmissionProgress();
})->hourly();