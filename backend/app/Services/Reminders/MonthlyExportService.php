<?php

namespace App\Services\Reminders;

use App\Models\{AttendanceDraft, AttendanceRecord, MonthlyExportTask, Program, Reminder, Student, User};
use App\Support\{GatheringCycle, SystemTimezone};
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MonthlyExportService
{
    public function scopes(User $user): array
    {
        if (!in_array($user->role, ['secretary', 'program_head', 'program_secretary'], true)) return [];
        $codes = $user->role === 'secretary' ? [$user->program] : $user->assignedProgramCodes();
        $scopes = [];
        foreach (Program::whereIn('code', $codes)->where('is_active', true)->get() as $program) {
            $maximum = $program->max_year_level ?: ($program->code === 'ACT' ? 2 : 4);
            $years = $user->role === 'secretary' ? [(int) $user->year_level] : range(1, $maximum);
            foreach ($years as $year) {
                if ($year >= 1 && $year <= $maximum) $scopes[] = [$program->code, $year];
            }
        }
        return $scopes;
    }

    public function authorize(User $user, MonthlyExportTask $task): void
    {
        abort_unless((int) $task->user_id === (int) $user->id, 403);
        abort_unless(in_array([$task->program, (int) $task->year_level], $this->scopes($user), true), 403, 'You no longer have access to this program and year.');
    }

    /** Eligibility is the day after the final PM/WS/TG window, not the reporting week's Tuesday. */
    public function eligibleAt(Carbon $month): Carbon
    {
        $last = GatheringCycle::weekStartForDate($month->copy()->endOfMonth());
        $end = collect(GatheringCycle::getRangesForWeekStart($last))->max(fn ($range) => $range['end']->timestamp);
        return Carbon::createFromTimestamp($end, SystemTimezone::timezone())->startOfDay()->addDay();
    }

    public function reconcile(?User $onlyUser = null): int
    {
        $created = 0;
        $snapshots = [];
        $users = User::whereIn('role', ['secretary', 'program_head', 'program_secretary'])
            ->where('is_active', true)->when($onlyUser, fn ($query) => $query->whereKey($onlyUser->id))->get();
        foreach ($users as $user) {
            $scopes = $this->scopes($user);
            $existing = MonthlyExportTask::where('user_id', $user->id)->get()->keyBy(
                fn ($task) => $task->program.'|'.$task->year_level.'|'.$task->month->toDateString()
            );
            // Catch up from account creation; reminders before the user existed are not assigned.
            $start = Carbon::parse($user->created_at ?? SystemTimezone::now())->startOfMonth();
            for ($month = $start; $month->lessThan(SystemTimezone::today()); $month->addMonth()) {
                $eligible = $this->eligibleAt($month);
                if ($eligible->greaterThan(SystemTimezone::now())) continue;
                foreach ($scopes as [$program, $year]) {
                    if ($existing->get($program.'|'.$year.'|'.$month->toDateString())?->reminder_id) continue;
                    DB::transaction(function () use ($user, $program, $year, $month, $eligible, &$created): void {
                        $identity = [
                            'user_id' => $user->id, 'program' => $program, 'year_level' => $year,
                            'month' => $month->toDateString(),
                        ];
                        $task = MonthlyExportTask::where('user_id', $user->id)->where('program', $program)
                            ->where('year_level', $year)->whereDate('month', $month->toDateString())
                            ->firstOrCreate([], $identity);
                        $task = MonthlyExportTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
                        if (!$task->reminder_id || !Reminder::whereKey($task->reminder_id)->exists()) {
                            $reminder = Reminder::create([
                                'user_id' => $user->id, 'reminder_type' => 'monthly_export',
                                'program' => $program, 'year_level' => $year,
                                'title' => 'Export '.$month->format('F Y').' attendance records',
                                'description' => "$program Year $year · Full monthly export: PM, WS, TG.",
                                'due_date' => $eligible, 'status' => 'needs_action',
                            ]);
                            $task->update(['reminder_id' => $reminder->id]);
                            $created++;
                        }
                    });
                }
            }
            foreach (MonthlyExportTask::where('user_id', $user->id)->get() as $task) {
                if (in_array([$task->program, (int) $task->year_level], $scopes, true)) {
                    $key = $task->program.'|'.$task->year_level.'|'.$task->month->toDateString();
                    $snapshots[$key] ??= $this->snapshot($task);
                    $this->refresh($task, $snapshots[$key]);
                }
            }
        }
        return $created;
    }

    /** Build a single immutable in-memory snapshot for hashing and file generation. */
    public function snapshot(MonthlyExportTask $task): array
    {
        $month = $task->month->copy();
        $start = GatheringCycle::weekStartForDate($month);
        $end = GatheringCycle::weekEndForDate($month->copy()->endOfMonth());
        $scope = function ($query) use ($task): void {
            $query->where(function ($snapshot) use ($task): void {
                $snapshot->where('program', $task->program)->where('year_level', $task->year_level);
            })->orWhere(function ($legacy) use ($task): void {
                $legacy->where(fn ($missing) => $missing->whereNull('program')->orWhereNull('year_level'))->whereHas('student', fn ($students) =>
                    $students->where('program', $task->program)->where('year_level', $task->year_level));
            });
        };
        $records = AttendanceRecord::where($scope)->inReportingRange([$start, $end])
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])->orderBy('id')->get();
        $drafts = AttendanceDraft::whereIn('status', ['draft', 'pending'])->inReportingRange([$start, $end])
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])->with('user')->orderBy('id')->get()
            ->filter(fn ($draft) => collect($draft->parsed_data ?? [])->contains(fn ($entry) =>
                ($entry['program'] ?? $draft->user?->program) === $task->program
                && (int) ($entry['year_level'] ?? $draft->user?->year_level) === (int) $task->year_level));
        $draftEntries = $drafts->flatMap(fn ($draft) => collect($draft->parsed_data ?? [])
            ->filter(fn ($entry) => ($entry['program'] ?? $draft->user?->program) === $task->program
                && (int) ($entry['year_level'] ?? $draft->user?->year_level) === (int) $task->year_level)
            ->map(fn ($entry) => [
            ...$entry, 'gathering_type' => $draft->gathering_type,
            'reporting_day' => $draft->day->toDateString(), 'validation_status' => 'draft',
        ]))->filter(fn ($entry) => !empty($entry['student_id']));
        $historicalIds = $records->pluck('student_id')->merge($draftEntries->pluck('student_id'))->unique();
        $students = Student::where(fn ($query) => $query->where(fn ($active) =>
                $active->active()->where('program', $task->program)->where('year_level', $task->year_level))
                ->orWhereIn('id', $historicalIds))
            ->with('escalation')->orderBy('name')->orderBy('id')->get();
        $bySlot = [];
        // Submitted records take precedence over a stale draft of the same slot.
        foreach ($draftEntries as $entry) {
            $date = GatheringCycle::getRangesForWeekStart(Carbon::parse($entry['reporting_day']))[$entry['gathering_type']]['start'];
            $entry['reporting_day'] = $date->toDateString();
            $row = new AttendanceRecord($entry);
            $bySlot[$row->student_id.'|'.$row->gathering_type.'|'.$date->toDateString()] = $row;
        }
        foreach ($records as $record) {
            $date = GatheringCycle::getRangesForWeekStart(Carbon::parse($record->reporting_day ?? $record->day))[$record->gathering_type]['start'];
            $record->reporting_day = $date;
            $bySlot[$record->student_id.'|'.$record->gathering_type.'|'.$date->toDateString()] = $record;
        }
        $rows = collect($bySlot)->values();
        foreach ($students as $student) {
            $student->setRelation('attendanceRecords', new Collection($rows->where('student_id', $student->id)->all()));
        }
        $weeks = intdiv((int) $start->diffInDays(GatheringCycle::weekStartForDate($month->copy()->endOfMonth())), 7) + 1;
        $missing = max(0, $students->count() * $weeks * 3 - $rows->count());
        $pending = $rows->reject(fn ($row) => $row->validation_status === 'validated')->count();
        $approved = $rows->isNotEmpty() && $pending === 0;
        $approvalSet = hash('sha256', json_encode($records->pluck('id')->sort()->values()->all()));
        $hash = hash('sha256', json_encode([
            $records->toArray(), $drafts->map->getAttributes()->values()->all(),
            $students->map(fn ($student) => [$student->id, $student->name, $student->is_active, $student->escalation?->escalation_level])->all(),
        ], JSON_THROW_ON_ERROR));
        $notice = "$pending pending/draft entries; $missing missing entries. Missing entries are not recorded No Response entries.";
        return compact('students', 'rows', 'hash', 'approved', 'approvalSet', 'pending', 'missing', 'notice');
    }

    public function refresh(MonthlyExportTask $task, ?array $snapshot = null): array
    {
        $snapshot ??= $this->snapshot($task);
        $unchanged = $task->exported_hash === $snapshot['hash'];
        $final = $task->exported_approved && $snapshot['approved'] && $task->exported_approval_set === $snapshot['approvalSet'];
        $done = $task->exported_at && ($unchanged || $final);
        $description = "$task->program Year $task->year_level · ".$snapshot['notice'];
        if (!$done && $task->exported_at) $description = 'Records updated—export again. '.$description;
        $reminder = $task->reminder;
        $reminder?->fill([
            'status' => $done ? 'completed' : 'needs_action',
            'completed_at' => $done ? $task->exported_at : null, 'expired_at' => null,
            'description' => $description,
        ]);
        if ($reminder?->isDirty()) $reminder->save();
        return $snapshot;
    }

    /** Remember revocations even if approval is restored before the next reminder refresh. */
    public function revokeFinalization(?string $program, $year, $day): void
    {
        if (!$program || !$year || !$day || !\Illuminate\Support\Facades\Schema::hasTable('monthly_export_tasks')) return;
        $week = GatheringCycle::weekStartForDate(Carbon::parse($day));
        MonthlyExportTask::where('program', $program)->where('year_level', $year)
            ->whereDate('month', '>=', $week->copy()->startOfMonth()->toDateString())
            ->whereDate('month', '<=', $week->copy()->addDays(6)->startOfMonth()->toDateString())
            ->whereNotNull('exported_at')
            ->update(['exported_approved' => false, 'exported_hash' => null]);
    }

    public function acknowledge(User $user, MonthlyExportTask $task, string $token): bool
    {
        $this->authorize($user, $task);
        return DB::transaction(function () use ($task, $token): bool {
            $task = MonthlyExportTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->download_token && hash_equals($task->download_token, hash('sha256', $token))
                && $task->download_expires_at?->isFuture(), 422, 'Export confirmation is invalid or expired. Please export again.');
            $snapshot = $this->snapshot($task);
            $current = $snapshot['hash'] === $task->download_hash;
            $task->update([
                'exported_hash' => $task->download_hash, 'exported_at' => SystemTimezone::now(),
                'exported_approved' => $task->download_approved && $current, 'exported_approval_set' => $task->download_approval_set,
                'download_token' => null, 'download_expires_at' => null,
            ]);
            $this->refresh($task->fresh(), $snapshot);
            return $current;
        });
    }
}
