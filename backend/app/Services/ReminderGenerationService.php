<?php

namespace App\Services;

use App\Models\Reminder;
use Carbon\Carbon;

class ReminderGenerationService
{
    /**
     * For every 'gathering' reminder whose gathering_date has arrived,
     * spawn a linked 'submission_countdown' reminder if one doesn't exist yet.
     */
    public function spawnSubmissionReminders(int $submissionWindowDays = 7): void
    {
        $dueGatherings = Reminder::where('reminder_type', 'gathering')
            ->whereDate('gathering_date', '<=', Carbon::today())
            ->whereDoesntHave('children') // no submission reminder spawned yet
            ->get();

        foreach ($dueGatherings as $gathering) {
            Reminder::create([
                'user_id'             => $gathering->user_id,
                'reminder_type'       => 'submission_countdown',
                'parent_reminder_id'  => $gathering->id,
                'gathering_type'      => $gathering->gathering_type,
                'program'             => $gathering->program,
                'year_level'          => $gathering->year_level,
                'gathering_date'      => $gathering->gathering_date,
                'title'               => 'Attendance record ready for submission',
                'description'         => $this->buildSubmissionDescription($gathering),
                'due_date'            => $gathering->gathering_date->copy()->addDays($submissionWindowDays),
                'status'              => 'needs_action',
            ]);
        }
    }

    /**
     * Refresh the "X/Y responded" text and status for all active submission reminders.
     */
    public function refreshSubmissionProgress(): void
    {
        $activeReminders = Reminder::where('reminder_type', 'submission_countdown')
            ->where('status', '!=', 'completed')
            ->get();

        foreach ($activeReminders as $reminder) {
            $responded = $reminder->respondedCount();
            $total = $reminder->totalStudents();
            $isComplete = $total > 0 && $responded >= $total;

            $reminder->update([
                'description'  => $this->buildSubmissionDescription($reminder, $responded, $total),
                'status'       => $isComplete ? 'completed' : 'needs_action',
                'completed_at' => $isComplete ? now() : null,
            ]);
        }
    }

    private function buildSubmissionDescription(Reminder $reminder, ?int $responded = null, ?int $total = null): string
    {
        $responded ??= $reminder->respondedCount();
        $total ??= $reminder->totalStudents();

        return sprintf(
            '%s · %s — %d/%d students responded',
            ucfirst($this->gatheringLabel($reminder->gathering_type)),
            $reminder->gathering_date->format('F j, Y'),
            $responded,
            $total
        );
    }

    private function gatheringLabel(string $type): string
    {
        return match ($type) {
            'pm'      => 'Prayer meeting',
            'ws'      => 'Worship service',
            'tg'      => 'Thanksgiving',
            'special' => 'Special gathering',
            default   => $type,
        };
    }
}