<?php

namespace App\Services;

use App\Models\Reminder;
use Carbon\Carbon;

class ReminderCountdownService
{
    private function calculateTimeRemaining($dueDate): string
    {
        $now = now();
        $due = Carbon::parse($dueDate);
        $diffInSeconds = $due->diffInSeconds($now, false);

        if ($diffInSeconds >= 0) {
            $daysPassed = (int) abs(floor($diffInSeconds / (60 * 60 * 24)));
            return 'Due date has passed' . ($daysPassed > 0 ? " ({$daysPassed} days ago)" : '');
        }

        $absSeconds = abs($diffInSeconds);
        $days = floor($absSeconds / (60 * 60 * 24));
        $hours = floor(($absSeconds % (60 * 60 * 24)) / (60 * 60));
        $minutes = floor(($absSeconds % (60 * 60)) / 60);
        $seconds = $absSeconds % 60;

        return sprintf('%d day/s, %d hour/s, %d minute/s, %d second/s', $days, $hours, $minutes, $seconds);
    }

    public function reminderCountdown(int $userId): array
    {
        $reminders = Reminder::where('user_id', $userId)->get();
        $countdownData = [];

        foreach ($reminders as $reminder) {
            $countdownData[$reminder->id] = $this->calculateTimeRemaining($reminder->due_date);
        }

        return $countdownData;
    }

    /**
     * Auto-flips 'upcoming' <-> 'needs_action' based on due_date.
     * Never touches reminders already marked 'completed' — those are
     * only changed by markCompleted() or refreshSubmissionProgress().
     */
    public function statusSetter(int $userId): void
    {
        $reminders = Reminder::where('user_id', $userId)
            ->where('status', '!=', 'completed')
            ->where('reminder_type', '!=', 'gathering')
            ->get();

        foreach ($reminders as $reminder) {
            $due = Carbon::parse($reminder->due_date);

            $reminder->status = now()->greaterThan($due) ? 'needs_action' : 'upcoming';
            $reminder->save();
        }
    }
}