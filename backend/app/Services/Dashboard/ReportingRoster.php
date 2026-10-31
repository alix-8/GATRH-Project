<?php

namespace App\Services\Dashboard;

use App\Models\Program;
use App\Models\Student;
use App\Models\AttendanceRecord;
use App\Models\AttendanceDraft;
use App\Support\GatheringCycle;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportingRoster
{
    public function programsForMonth(Carbon $month): Collection
    {
        if ($month->greaterThanOrEqualTo(now()->startOfMonth())) {
            return Program::query()->active()->orderBy('code')->get();
        }

        $start = GatheringCycle::weekStartForDate($month);
        $end = GatheringCycle::weekEndForDate($month->copy()->endOfMonth());
        $recordedCodes = AttendanceRecord::query()->with('student')
            ->inReportingRange([$start, $end])->get()
            ->map(fn ($row) => $row->program ?? $row->student?->program);
        $draftCodes = AttendanceDraft::query()->whereBetween('day', [$start->toDateString(), $end->toDateString()])
            ->get()->flatMap(fn ($draft) => collect($draft->parsed_data ?? [])->pluck('program'));
        $historicalCodes = $recordedCodes->merge($draftCodes)->filter()->unique();

        return Program::query()->orderBy('code')->get()->filter(fn (Program $program) =>
            $program->wasActiveOn($month->copy()->endOfMonth()->toDateString())
            || $historicalCodes->contains($program->code)
        )->values();
    }

    /** Resolve expectations at the gathering date, without hiding saved history. */
    public function studentIds(Collection $students, Collection $records, string $date, ?Program $program = null): array
    {
        $historical = $date < now()->toDateString();
        $recordedIds = $historical ? $records->pluck('student_id')->map(fn ($id) => (int) $id)->all() : [];

        return $students->filter(function (Student $student) use ($date, $program, $recordedIds) {
            if (in_array((int) $student->id, $recordedIds, true)) {
                return true;
            }
            return $student->wasActiveOn($date) && ($program === null || $program->wasActiveOn($date));
        })->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
