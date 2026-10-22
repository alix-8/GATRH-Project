<?php

namespace App\Services\Reminders;

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
            $elapsedSeconds = (int) abs($diffInSeconds);
            $days = intdiv($elapsedSeconds, 86400);
            $hours = intdiv($elapsedSeconds % 86400, 3600);
            $minutes = intdiv($elapsedSeconds % 3600, 60);
            $seconds = $elapsedSeconds % 60;
            $parts = $this->durationParts($days, $hours, $minutes, $seconds);

            return 'Due date has passed (' . implode(', ', $parts) . ' ago)';
        }

        $absSeconds = abs($diffInSeconds);
        $days = floor($absSeconds / (60 * 60 * 24));
        $hours = floor(($absSeconds % (60 * 60 * 24)) / (60 * 60));
        $minutes = floor(($absSeconds % (60 * 60)) / 60);
        $seconds = $absSeconds % 60;
        $parts = $this->durationParts((int) $days, (int) $hours, (int) $minutes, (int) $seconds);

        return implode(', ', $parts);
    }

    private function durationParts(int $days, int $hours, int $minutes, int $seconds): array
    {
        $parts = [];

        if ($days > 0) $parts[] = "{$days} day/s";
        if ($hours > 0) $parts[] = "{$hours} hour/s";
        if ($minutes > 0) $parts[] = "{$minutes} minute/s";
        if ($seconds > 0 || $parts === []) $parts[] = "{$seconds} second/s";

        return $parts;
    }

    public function reminderCountdown(int $userId): array
    {
        return $this->reminderCountdownFor(Reminder::where('user_id', $userId)->get());
    }

    /** Build countdown labels from reminders already loaded by the caller. */
    public function reminderCountdownFor(iterable $reminders): array
    {
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
        $now = now();
        $eligibleReminders = Reminder::where('user_id', $userId)
            ->where('status', '!=', 'completed')
            ->where('reminder_type', '!=', 'gathering');

        // Update only records whose status actually changes. The previous
        // per-row save caused one database write per reminder on every GET.
        (clone $eligibleReminders)
            ->where('due_date', '<', $now)
            ->where('status', '!=', 'needs_action')
            ->update(['status' => 'needs_action']);

        (clone $eligibleReminders)
            ->where('due_date', '>=', $now)
            ->where('status', '!=', 'upcoming')
            ->update(['status' => 'upcoming']);
    }
}
