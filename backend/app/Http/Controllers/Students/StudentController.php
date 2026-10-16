<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\GatheringSchedule;
use App\Models\Student;
use App\Services\Escalations\WeeklyEscalationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Student::query()->active()->accessibleTo($request->user())->get()
        );
    }
    public function attendanceSnapshot(Request $request)
    {
        $user = $request->user();
        $students = Student::query()
            ->active()
            ->accessibleTo($user)
            ->with('escalation')
            ->orderBy('name')
            ->get();
        $weeklyEscalationCounts = app(WeeklyEscalationService::class)
            ->countsForStudents($students->pluck('id'));

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $recordsByStudent = AttendanceRecord::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereBetween('day', [$weekStart, $weekEnd])
            ->orderByDesc('created_at')
            ->orderByDesc('day')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($records) => $records->first());
        $drafts = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->whereBetween('day', [$weekStart, $weekEnd])
            ->orderByDesc('day')
            ->orderByDesc('updated_at')
            ->get();
        $responses = collect();

        foreach ($students as $student) {
            $latestRecord = $recordsByStudent->get($student->id);
            $latestDraft = null;

            foreach ($drafts as $draft) {
                $draftRecord = collect($draft->parsed_data ?? [])
                    ->first(fn (array $record) => (int) ($record['student_id'] ?? 0) === $student->id);

                if ($draftRecord) {
                    $latestDraft = [$draft, $draftRecord];
                    break;
                }
            }

            $draftIsNewer = $latestDraft && (! $latestRecord
                || $latestDraft[0]->day->gt($latestRecord->day)
                || ($latestDraft[0]->day->equalTo($latestRecord->day)
                    && $latestDraft[0]->updated_at->gt($latestRecord->created_at)));

            if ($draftIsNewer) {
                $responses->put($student->id, $latestDraft[1]);
            } elseif ($latestRecord) {
                $responses->put($student->id, $latestRecord);
            }
        }
        $session = $recordsByStudent->isNotEmpty() || $drafts->isNotEmpty()
            ? ['source' => 'weekly']
            : null;

        return response()->json([
            'students' => $students->map(function (Student $student) use ($responses, $session, $weeklyEscalationCounts) {
                $record = $responses->get($student->id);
                $status = $record ? ($record['status'] ?? $record->status) : null;
                $hasResponded = $status === 'present';

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'program' => $student->program,
                    'year_level' => $student->year_level,
                    'response' => $session ? ($hasResponded ? 'Responded' : 'No Response') : null,
                    'escalation_level' => $student->escalation->escalation_level ?? 'None',
                    'no_response_count' => min(3, $weeklyEscalationCounts->get($student->id, 0)),
                ];
            })->values(),
            'session' => $session,
        ]);
    }
    /** Return the selected student's attendance details for the overview drawer. */
    public function overview(Request $request, int $id)
    {
        $student = Student::query()
            ->accessibleTo($request->user())
            ->with('escalation')
            ->findOrFail($id);

        $history = $student->attendanceRecords()
            ->orderByDesc('day')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AttendanceRecord $record) => [
                'id' => 'record-' . $record->id,
                'gathering_type' => $record->gathering_type,
                'gathering' => $this->gatheringLabel($record->gathering_type),
                'time' => $record->batch ? substr($record->batch, 0, 5) : '—',
                'date' => $record->day->toDateString(),
                'location' => $record->venue ?: '—',
                'status' => $record->status,
            ]);

        // The latest draft is included so a secretary can see the import right
        // away, before the attendance batch is submitted.
        $draft = AttendanceDraft::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->orderByDesc('day')
            ->orderByDesc('updated_at')
            ->first();

        if ($draft) {
            $draftRecord = collect($draft->parsed_data ?? [])
                ->first(fn (array $record) => (int) ($record['student_id'] ?? 0) === $student->id);

            if ($draftRecord) {
                $history->prepend([
                    'id' => 'draft-' . $draft->id,
                    'gathering_type' => $draft->gathering_type,
                    'gathering' => $this->gatheringLabel($draft->gathering_type),
                    'time' => $draftRecord['batch'] ?? '—',
                    'date' => $draftRecord['day'] ?? optional($draft->day)->toDateString(),
                    'location' => $draftRecord['venue'] ?? '—',
                    'status' => $draftRecord['status'] ?? 'no_response',
                ]);
            }
        }

        $history = $history->sortByDesc('date')->values();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $weeklyEscalationCount = min(3, $history
            ->filter(fn (array $record) => str_starts_with($record['id'] ?? '', 'record-')
                && ($record['date'] ?? '') >= $weekStart
                && ($record['date'] ?? '') <= $weekEnd
                && ($record['status'] ?? null) === 'no_response')
            ->pluck('gathering_type')
            ->unique()
            ->count());
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $thisMonth = $student->attendanceRecords()
            ->whereBetween('day', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->where('status', 'present')
            ->count();
        $thisMonthTotal = $this->scheduledGatheringCount($monthStart, $monthEnd);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'program' => $student->program,
                'year_level' => $student->year_level,
                'no_response_count' => $weeklyEscalationCount,
            ],
            'month' => $monthStart->format('F Y'),
            'summary' => [
                'this_month' => $thisMonth,
                'this_month_total' => $thisMonthTotal,
                'attendance_count' => $history->count(),
                'escalation_count' => $weeklyEscalationCount,
            ],
            'history' => $history,
        ]);
    }

    private function gatheringLabel(?string $type): string
    {
        return match ($type) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanksgiving',
            default => 'Gathering',
        };
    }

    private function scheduledGatheringCount(Carbon $monthStart, Carbon $monthEnd): int
    {
        $schedules = GatheringSchedule::query()->get()->keyBy('gathering_type');
        $total = 0;

        foreach (['pm', 'ws', 'tg'] as $type) {
            $scheduledDays = collect($schedules->get($type)?->days ?? [])
                ->map(fn ($day) => strtolower((string) $day))
                ->all();
            $scheduledWeeks = [];

            for ($date = $monthStart->copy(); $date->lte($monthEnd); $date->addDay()) {
                if (in_array(strtolower($date->format('l')), $scheduledDays, true)) {
                    $scheduledWeeks[$date->isoWeekYear . '-' . $date->isoWeek()] = true;
                }
            }

            $total += count($scheduledWeeks);
        }

        return $total;
    }
}
