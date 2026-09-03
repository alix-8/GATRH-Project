<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\ReminderGenerationService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// --- Reminder generation jobs ---

Schedule::call(function () {
    app(ReminderGenerationService::class)->spawnSubmissionReminders();
})->daily();

Schedule::call(function () {
    app(ReminderGenerationService::class)->refreshSubmissionProgress();
})->hourly();