<?php

//Service for ReminderController

namespace App\Services;

use App\Models\Reminder;
use AddressInfo\Carbon\Carbon;

class ReminderService {

    private function calculateTimeRemaining($dueDate) {
        $now = now();
        $due = \Carbon\Carbon::parse($dueDate);
        $diffInSeconds = $due->diffInSeconds($now, false);

        if ($diffInSeconds >= 0) {
            $dayspassed = abs(floor($diffInSeconds / (60 * 60 * 24)));

            return 'Due date has passed' . ($dayspassed > 0 ? " ($dayspassed days ago)" : '');
        }

        $days = floor($diffInSeconds / (60 * 60 * 24));
        $hours = floor(($diffInSeconds % (60 * 60 * 24)) / (60 * 60));
        $minutes = floor(($diffInSeconds % (60 * 60)) / 60);
        $seconds = $diffInSeconds % 60;

        return sprintf('%d days, %d hours, %d minutes, %d seconds', $days, $hours, $minutes, $seconds);
    }
    
    public function ReminderCountdown($userId) {
        $reminders = Reminder::where('user_id', $userId)->get();
        $countdownData = [];

        foreach ($reminders as $reminder) {
            $countdownData[$reminder->id] = $this->calculateTimeRemaining($reminder->due_date);
        }

        return $countdownData;
    }

    public function StatusSetter($userId) {
        $reminders = Reminder::where('user_id', $userId)->get();
        $statusData = [];

        foreach ($reminders as $reminder) {
            $now = now();
            $due = \Carbon\Carbon::parse($reminder->due_date);

            if ($now->greaterThan($due)) {
                $reminder->status = 'needs_action';
            } else {
                $reminder->status = 'upcoming';
            }
            $statusData[$reminder->id] = $reminder;
            $reminder->save();
        }
    }
}