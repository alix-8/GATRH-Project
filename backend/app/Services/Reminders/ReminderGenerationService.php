<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\GatheringCycle;
use App\Support\SystemTimezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReminderGenerationService
{
    public function __construct(private GatheringService $gatheringService)
    {
    }

    /**
     * Create the next weekly PM, WS, and TG reminders for every secretary.
     * Existing reminders for the same user, type, and date are left untouched.
     */
    public function generateWeeklyGatheringReminders(?User $onlyUser = null): int
    {   
        $today = SystemTimezone::today();
        $currentWeekEnd = GatheringCycle::weekEndForDate($today);

        Reminder::where('reminder_type', 'gathering')
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])
            ->where(function ($query) use ($today, $currentWeekEnd) {
                $query->whereDate('gathering_date', '<', $today)
                    ->orWhereDate('gathering_date', '>', $currentWeekEnd);
            })
            ->delete();
        
        $created = 0;
        $secretaries = User::where('role', 'secretary')
            ->whereNotNull('program')
            ->whereNotNull('year_level')
            ->when($onlyUser, fn ($query) => $query->whereKey($onlyUser->id))
            ->get(['id', 'program', 'year_level']);

        $gatheringDates = collect(['pm', 'ws', 'tg'])
            ->mapWithKeys(fn (string $type) => [$type => $this->gatheringService->nextDate($type)]);
        $secretaryIds = $secretaries->pluck('id');

        // Load existing attendance and reminders once, instead of checking each
        // secretary/type pair with separate EXISTS queries.
        $attendanceKeys = AttendanceRecord::query()
            ->whereIn('submitted_by', $secretaryIds)
            ->where(function ($query) use ($gatheringDates): void {
                foreach ($gatheringDates as $type => $date) {
                    $query->orWhere(function ($pair) use ($type, $date): void {
                        $pair->where('gathering_type', $type)->whereDate('day', $date);
                    });
                }
            })
            ->get(['submitted_by', 'gathering_type', 'day'])
            ->mapWithKeys(fn (AttendanceRecord $record) => [
                $this->gatheringKey($record->submitted_by, $record->gathering_type, $record->day) => true,
            ]);

        $reminderKeys = Reminder::query()
            ->whereIn('user_id', $secretaryIds)
            ->where('reminder_type', 'gathering')
            ->where(function ($query) use ($gatheringDates): void {
                foreach ($gatheringDates as $type => $date) {
                    $query->orWhere(function ($pair) use ($type, $date): void {
                        $pair->where('gathering_type', $type)->whereDate('gathering_date', $date);
                    });
                }
            })
            ->get(['user_id', 'gathering_type', 'gathering_date'])
            ->mapWithKeys(fn (Reminder $reminder) => [
                $this->gatheringKey($reminder->user_id, $reminder->gathering_type, $reminder->gathering_date) => true,
            ]);

        $staleRemindersDeleted = false;
        foreach ($secretaries as $secretary) {
            foreach ($gatheringDates as $type => $gatheringDate) {
                $key = $this->gatheringKey($secretary->id, $type, $gatheringDate);

                // Do not recreate a gathering reminder after attendance was submitted.
                if ($attendanceKeys->has($key) || $reminderKeys->has($key)) {
                    continue;
                }

                // Preserve the existing cleanup, but run it only once when at
                // least one new reminder is actually going to be created.
                if (! $staleRemindersDeleted) {
                    $currentWeekStart = GatheringCycle::weekStartForDate(SystemTimezone::today());
                    $currentWeekEnd = GatheringCycle::weekEndForDate(SystemTimezone::today());

                    Reminder::where('reminder_type', 'gathering')
                        ->whereIn('gathering_type', ['pm', 'ws', 'tg'])
                        ->whereNotBetween('gathering_date', [
                            $currentWeekStart->toDateString(),
                            $currentWeekEnd->toDateString(),
                        ])
                        ->delete();

                    $staleRemindersDeleted = true;
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
        }

        return $created;
    }

    /**
     * For every 'gathering' reminder whose gathering_date has arrived,
     * spawn a linked 'submission_countdown' reminder if one doesn't exist yet.
     */
    public function spawnSubmissionReminders(int $submissionWindowDays = 7, ?int $onlyUserId = null): void
    {
        $dueGatherings = Reminder::where('reminder_type', 'gathering')
            ->whereDate('gathering_date', '<=', SystemTimezone::today())
            ->when($onlyUserId, fn ($query) => $query->where('user_id', $onlyUserId))
            ->whereDoesntHave('children') // no submission reminder spawned yet
            ->get();

        [$totalCounts, $respondedCounts] = $this->submissionProgressCounts($dueGatherings);

        foreach ($dueGatherings as $gathering) {
            $total = $totalCounts->get($this->progressKey($gathering->program, $gathering->year_level), 0);
            $responded = $respondedCounts->get($this->progressKey(
                $gathering->program,
                $gathering->year_level,
                $gathering->gathering_type,
                $gathering->gathering_date->toDateString()
            ), 0);

            Reminder::create([
                'user_id'             => $gathering->user_id,
                'reminder_type'       => 'submission_countdown',
                'parent_reminder_id'  => $gathering->id,
                'gathering_type'      => $gathering->gathering_type,
                'program'             => $gathering->program,
                'year_level'          => $gathering->year_level,
                'gathering_date'      => $gathering->gathering_date,
                'title'               => 'Attendance record ready for submission',
                'description'         => $this->buildSubmissionDescription($gathering, $responded, $total),
                'due_date'            => $gathering->gathering_date->copy()->addDays($submissionWindowDays),
                'status'              => 'needs_action',
            ]);
        }
    }

    /**
     * Refresh the "X/Y responded" text and status for all active submission reminders.
     */
    public function refreshSubmissionProgress(?int $onlyUserId = null): void
    {
        $activeReminders = Reminder::where('reminder_type', 'submission_countdown')
            ->when($onlyUserId, fn ($query) => $query->where('user_id', $onlyUserId))
            ->get();

        if ($activeReminders->isEmpty()) {
            return;
        }

        [$totalCounts, $respondedCounts] = $this->submissionProgressCounts($activeReminders);

        $updates = [];

        foreach ($activeReminders as $reminder) {
            $total = $totalCounts->get($this->progressKey($reminder->program, $reminder->year_level), 0);
            $responded = $respondedCounts->get($this->progressKey(
                $reminder->program,
                $reminder->year_level,
                $reminder->gathering_type,
                $reminder->gathering_date->toDateString()
            ), 0);
            $range = GatheringCycle::getRangesForWeekStart(Carbon::parse($reminder->gathering_date))[$reminder->gathering_type];
            // A submission task is finished by submitting, not by getting every student's response.
            $isComplete = AttendanceRecord::where('submitted_by', $reminder->user_id)
                ->where('gathering_type', $reminder->gathering_type)
                ->inReportingRange([$range['start'], $range['end']])
                ->where(function ($query) use ($reminder): void {
                    $query->where(fn ($snapshot) => $snapshot->where('program', $reminder->program)->where('year_level', $reminder->year_level))
                        ->orWhere(fn ($legacy) => $legacy->whereNull('program')->whereHas('student', fn ($students) =>
                            $students->where('program', $reminder->program)->where('year_level', $reminder->year_level)));
                })->exists();

            $values = [
                'description'  => $this->buildSubmissionDescription($reminder, $responded, $total),
                'status'       => $isComplete ? 'completed' : 'needs_action',
                'completed_at' => $isComplete ? ($reminder->completed_at ?? SystemTimezone::now()) : null,
            ];
            $reminder->fill($values);
            if ($reminder->isDirty()) {
                $updates[] = ['id' => $reminder->id, ...$values, 'updated_at' => SystemTimezone::now()];
            }
        }

        // Update existing rows only; an upsert can fail on required fields or recreate a deleted reminder.
        if ($updates !== []) {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as $update) {
                    $id = $update['id'];
                    unset($update['id']);
                    Reminder::whereKey($id)->update($update);
                }
            });
        }
    }

    /**
     * Remove reminders that have remained expired beyond their retention window.
     */
    public function deleteExpiredReminders(
    int $submissionRetentionDays = 7
    ): int {
        $today = SystemTimezone::today();
        $currentWeekEnd = GatheringCycle::weekEndForDate($today);

        // Keep submission tasks for seven days after their deadline.
        $submissionCutoff = $today->copy()
            ->subDays($submissionRetentionDays);

        $deletedSubmissionReminders = Reminder::where(
            'reminder_type',
            'submission_countdown'
        )
            ->whereDate('due_date', '<=', $submissionCutoff)
            ->delete();

        // Delete gathering reminders that are already past
        // or belong to next week.
        $deletedGatheringReminders = Reminder::where(
            'reminder_type',
            'gathering'
        )
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])
            ->where(function ($query) use ($today, $currentWeekEnd) {
                $query->whereDate('gathering_date', '<', $today)
                    ->orWhereDate('gathering_date', '>', $currentWeekEnd);
            })
            ->delete();

        return $deletedSubmissionReminders + $deletedGatheringReminders;
    }

    /**
     * Mark completed reminders as expired during the weekly cleanup, retaining their history.
     */
    public function expireCompletedReminders(): int
    {
        return Reminder::where('status', 'completed')
            ->where('reminder_type', '!=', 'monthly_export')
            ->whereNull('expired_at')
            ->update(['expired_at' => SystemTimezone::now()]);
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

    private function gatheringKey(int $userId, string $type, mixed $date): string
    {
        return $userId . '|' . $type . '|' . Carbon::parse($date)->toDateString();
    }

    private function progressKey(string $program, string $yearLevel, ?string $type = null, mixed $date = null): string
    {
        $key = strtoupper(trim($program)) . '|' . $yearLevel;

        if ($type !== null) {
            $key .= '|' . $type . '|' . Carbon::parse($date)->toDateString();
        }

        return $key;
    }

    /** Load reminder response totals in two grouped queries for a collection of reminders. */
    private function submissionProgressCounts(Collection $reminders): array
    {
        if ($reminders->isEmpty()) {
            return [collect(), collect()];
        }

        $programs = $reminders->pluck('program')->unique()->values();
        $yearLevels = $reminders->pluck('year_level')->unique()->values();
        $gatheringTypes = $reminders->pluck('gathering_type')->unique()->values();
        $gatheringDates = $reminders->pluck('gathering_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        $totalCounts = DB::table('students')
            ->select('program', 'year_level')
            ->selectRaw('COUNT(*) AS total')
            ->where('is_active', true)
            ->whereIn('program', $programs)
            ->whereIn('year_level', $yearLevels)
            ->groupBy('program', 'year_level')
            ->get()
            ->reduce(function ($counts, $row) {
                $key = $this->progressKey($row->program, $row->year_level);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->total;

                return $counts;
            }, collect());

        $respondedCounts = DB::table('attendance_records')
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->select(
                'students.program',
                'students.year_level',
                'attendance_records.gathering_type',
                'attendance_records.day'
            )
            ->selectRaw('COUNT(*) AS responded')
            ->where('students.is_active', true)
            ->whereIn('students.program', $programs)
            ->whereIn('students.year_level', $yearLevels)
            ->whereIn('attendance_records.gathering_type', $gatheringTypes)
            ->whereIn('attendance_records.day', $gatheringDates)
            ->whereIn('attendance_records.status', ['present', 'excused'])
            ->groupBy(
                'students.program',
                'students.year_level',
                'attendance_records.gathering_type',
                'attendance_records.day'
            )
            ->get()
            ->reduce(function ($counts, $row) {
                $key = $this->progressKey($row->program, $row->year_level, $row->gathering_type, $row->day);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->responded;

                return $counts;
            }, collect());

        return [$totalCounts, $respondedCounts];
    }

}
