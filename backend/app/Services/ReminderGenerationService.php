<?php

namespace App\Services;

use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;

class ReminderGenerationService
{
    public function __construct(private GatheringService $gatheringService)
    {
    }

    /**
     * Create the next weekly PM, WS, and TG reminders for every secretary.
     * Existing reminders for the same user, type, and date are left untouched.
     */
    public function generateWeeklyGatheringReminders(): int
    {
        $created = 0;

        User::where('role', 'secretary')
            ->whereNotNull('program')
            ->whereNotNull('year_level')
            ->each(function (User $secretary) use (&$created): void {
                foreach (['pm', 'ws', 'tg'] as $type) {
                    $gatheringDate = $this->gatheringService->nextDate($type);

                    $alreadyExists = Reminder::where('user_id', $secretary->id)
                        ->where('reminder_type', 'gathering')
                        ->where('gathering_type', $type)
                        ->whereDate('gathering_date', $gatheringDate)
                        ->exists();

                    if ($alreadyExists) {
                        continue;
                    }

                    Reminder::create([
                        'user_id'        => $secretary->id,
                        'reminder_type'  => 'gathering',
                        'gathering_type' => $type,
                        'program'        => strtoupper($secretary->program),
                        'year_level'     => $secretary->year_level,
                        'gathering_date' => $gatheringDate,
                        'title'          => ucfirst($this->gatheringService->label($type)),
                        'description'    => strtoupper($secretary->program) . '-' . $secretary->year_level,
                        'due_date'       => $gatheringDate,
                        'status'         => 'upcoming',
                    ]);

                    $created++;
                }
            });

        return $created;
    }

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

    /**
     * Remove reminders that have remained expired beyond their retention window.
     */
    public function deleteExpiredReminders(int $submissionRetentionDays = 7): int
    {
        // Monday of the current week.
        // Anything before this belongs to last week or earlier.
        $currentWeekStart = Carbon::today()->startOfWeek();

        // Keep submission tasks for seven days after their deadline.
        $submissionCutoff = Carbon::today()
            ->subDays($submissionRetentionDays);

        $deletedSubmissionReminders = Reminder::where(
            'reminder_type',
            'submission_countdown'
        )
            ->whereDate('due_date', '<=', $submissionCutoff)
            ->delete();

        // Remove PM, WS, and TG reminders from previous weeks.
        $deletedGatheringReminders = Reminder::where(
            'reminder_type',
            'gathering'
        )
            ->whereDate('gathering_date', '<', $currentWeekStart)
            ->delete();

        return $deletedSubmissionReminders + $deletedGatheringReminders;
    }

    private function buildSubmissionDescription(Reminder $reminder, ?int $responded = null, ?int $total = null): string
    {
        $responded ??= $reminder->respondedCount();
        $total ??= $reminder->totalStudents();

        return sprintf(
            '%s · %s — %d/%d students responded',
            ucfirst($this->gatheringService->label($reminder->gathering_type)),
            $reminder->gathering_date->format('F j, Y'),
            $responded,
            $total
        );
    }

}