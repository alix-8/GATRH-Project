<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\GatheringSchedule;
use App\Models\Student;
use App\Services\Escalations\WeeklyEscalationService;
use App\Support\GatheringCycle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $weekStart = GatheringCycle::weekStartForDate(Carbon::now())->toDateString();
        $weekEnd = GatheringCycle::weekEndForDate(Carbon::now())->toDateString();
        $weeklyRecords = AttendanceRecord::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereBetween('day', [$weekStart, $weekEnd])
            ->orderByDesc('day')
            ->orderByDesc('created_at')
            ->get();
        $recordsByStudent = $weeklyRecords
            ->groupBy('student_id')
            ->map(fn ($records) => $records->first());
        $drafts = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->whereBetween('day', [$weekStart, $weekEnd])
            ->orderByDesc('day')
            ->orderByDesc('updated_at')
            ->get();
        $latestRecord = $weeklyRecords->first();
        $latestDraft = $drafts->first();
        $latestDraftIsNewer = $latestDraft && (! $latestRecord
            || $latestDraft->day->gt($latestRecord->day)
            || ($latestDraft->day->equalTo($latestRecord->day)
                && $latestDraft->updated_at->gt($latestRecord->created_at)));
        $latestGatheringType = $latestDraftIsNewer
            ? $latestDraft->gathering_type
            : $latestRecord?->gathering_type;
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
                $responses->put($student->id, [
                    'status' => $latestDraft[1]['status'] ?? 'no_response',
                ]);
            } elseif ($latestRecord) {
                $responses->put($student->id, $latestRecord);
            }
        }
        $session = $recordsByStudent->isNotEmpty() || $drafts->isNotEmpty()
            ? ['source' => 'weekly']
            : null;

        // Keep the existing latest-response summary; expose gathering-specific
        // responses separately for the table's gathering navigation.
        $gatheringResponses = collect(['pm', 'ws', 'tg'])->mapWithKeys(function ($type) use ($students, $weeklyRecords, $drafts) {
            $records = $weeklyRecords->where('gathering_type', $type)->groupBy('student_id');
            $typeDrafts = $drafts->where('gathering_type', $type);
            $hasSession = $records->isNotEmpty() || $typeDrafts->isNotEmpty();

            return [$type => $students->mapWithKeys(function ($student) use ($records, $typeDrafts, $hasSession) {
                $record = $records->get($student->id)?->first();
                $status = $record?->status;
                foreach ($typeDrafts as $draft) {
                    $entry = collect($draft->parsed_data ?? [])->first(fn ($entry) => (int) ($entry['student_id'] ?? 0) === $student->id);
                    if (! $entry) continue;
                    if (! $record || $draft->day->gt($record->day)
                        || ($draft->day->equalTo($record->day) && $draft->updated_at->gt($record->created_at))) {
                        $status = $entry['status'] ?? 'no_response';
                    }
                    break;
                }

                return [$student->id => $hasSession ? ($status === 'present' ? 'Responded' : 'No Response') : null];
            })];
        });

        return response()->json([
            'week_range' => [
                'start' => $weekStart,
                'end' => $weekEnd,
            ],
            'students' => $students->map(function (Student $student) use ($responses, $session, $weeklyEscalationCounts, $gatheringResponses) {
                $record = $responses->get($student->id);
                $status = $record ? ($record['status'] ?? $record->status) : null;
                $hasResponded = $status === 'present';

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'program' => $student->program,
                    'year_level' => $student->year_level,
                    'response' => $session ? ($hasResponded ? 'Responded' : 'No Response') : null,
                    'responses_by_gathering' => $gatheringResponses->map(fn ($responses) => $responses->get($student->id)),
                    'escalation_level' => $student->escalation->escalation_level ?? 'None',
                    'no_response_count' => min(3, $weeklyEscalationCounts->get($student->id, 0)),
                ];
            })->values(),
            'session' => $session,
            'latest_gathering_type' => $session ? $latestGatheringType : null,
        ]);
    }
    /** Return the selected student's attendance details for the overview drawer. */
    public function overview(Request $request, int $id)
    {
        return $this->overviewResponse($request, $id);
    }

    public function overviewCurrentWeek(Request $request, int $id)
    {
        return $this->overviewResponse($request, $id, true);
    }

    public function overviewHistory(Request $request, int $id)
    {
        $student = Student::query()
            ->accessibleTo($request->user())
            ->findOrFail($id);

        return response()->json(['history' => $this->attendanceHistory($request, $student)]);
    }

    /**
     * Show only the current-week no-response entries that produce the
     * escalation. This intentionally differs from the full Student Overview.
     */
    public function escalationEvidence(Request $request, int $id)
    {
        $user = $request->user();
        $student = Student::query()
            ->active()
            ->accessibleTo($user)
            ->findOrFail($id);

        $includeDrafts = in_array($user->role, ['secretary', 'cm_coordinator'], true);
        $draftOwnerId = $user->role === 'secretary' ? $user->id : null;
        $evidence = app(WeeklyEscalationService::class)
            ->evidenceForStudent($student->id, $includeDrafts, $draftOwnerId);
        $weekStart = GatheringCycle::weekStartForDate(Carbon::now())->toDateString();
        $weekEnd = GatheringCycle::weekEndForDate(Carbon::now())->toDateString();

        return response()->json([
            'week_range' => ['start' => $weekStart, 'end' => $weekEnd],
            'history' => $evidence->map(fn (array $entry) => [
                'id' => $entry['id'],
                'gathering_type' => $entry['gathering_type'],
                'gathering' => $this->gatheringLabel($entry['gathering_type']),
                'time' => $entry['time'],
                'date' => $entry['day'],
                'location' => $entry['location'],
                'status' => 'no_response',
                'source' => $entry['source'],
            ])->values(),
        ]);
    }

    private function overviewResponse(Request $request, int $id, bool $currentWeekOnly = false)
    {
        $student = Student::query()
            ->accessibleTo($request->user())
            ->with('escalation')
            ->findOrFail($id);

        $weekStart = GatheringCycle::weekStartForDate(Carbon::now());
        $weekEnd = GatheringCycle::weekEndForDate(Carbon::now());
        $draftHistory = $this->draftHistory($request, $student);
        $history = $this->attendanceHistory(
            $request,
            $student,
            $draftHistory,
            $currentWeekOnly ? $weekStart : null,
            $currentWeekOnly ? $weekEnd : null,
        );
        $attendanceCount = $currentWeekOnly
            ? $student->attendanceRecords()->count() + $draftHistory->count()
            : $history->count();
        $weeklyEscalationCount = app(WeeklyEscalationService::class)
            ->countsForStudents([$student->id])
            ->get($student->id, 0);
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $monthEntries = $this->attendanceEntriesForPeriod($student, $monthStart, $monthEnd);
        $thisMonth = collect($monthEntries)->where('status', 'present')->count();
        $weekEntries = $this->attendanceEntriesForPeriod(
            $student,
            GatheringCycle::weekStartForDate(Carbon::now()),
            GatheringCycle::weekEndForDate(Carbon::now())
        );
        $thisWeek = collect(['pm', 'ws', 'tg'])->map(function (string $type) use ($weekEntries) {
            $entry = collect($weekEntries)
                ->where('gathering_type', $type)
                ->sortByDesc('date')
                ->first();

            return  [
                'type' => $type,
                'label' => $this->gatheringLabel($type),
                'status' => $entry['status'] ?? null,
            ];
        })->values();
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
                'this_week' => $thisWeek,
                'attendance_count' => $attendanceCount,
                'escalation_count' => $weeklyEscalationCount,
            ],
            'history' => $history,
        ]);
    }

    private function attendanceHistory(
        Request $request,
        Student $student,
        ?Collection $draftHistory = null,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): Collection {
        $records = $student->attendanceRecords()
            ->when($start && $end, fn ($query) => $query->whereBetween('day', [$start->toDateString(), $end->toDateString()]))
            ->orderByDesc('day')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($record) => [
                'id' => 'record-' . $record->id,
                'program' => $record->program ?? $student->program,
                'year_level' => $record->year_level ?? $student->year_level,
                'gathering_type' => $record->gathering_type,
                'gathering' => $this->gatheringLabel($record->gathering_type),
                'time' => $record->batch ? substr($record->batch, 0, 5) : '—',
                'date' => $record->day->toDateString(),
                'location' => $record->venue ?: '—',
                'status' => $record->status,
            ]);

        $drafts = $draftHistory ?? $this->draftHistory($request, $student);
        if ($start && $end) {
            $drafts = $drafts->filter(fn (array $record) => $record['date'] >= $start->toDateString()
                && $record['date'] <= $end->toDateString());
        }

        return $records->concat($drafts)->sortByDesc('date')->values();
    }

    private function draftHistory(Request $request, Student $student): Collection
    {
        return AttendanceDraft::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->orderByDesc('day')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($draft) use ($student) {
                $record = collect($draft->parsed_data ?? [])
                    ->first(fn (array $record) => (int) ($record['student_id'] ?? 0) === $student->id);

                if (! $record) {
                    return null;
                }

                return [
                    'id' => 'draft-' . $draft->id,
                    'gathering_type' => $draft->gathering_type,
                    'gathering' => $this->gatheringLabel($draft->gathering_type),
                    'time' => $record['batch'] ?? '—',
                    'date' => $record['day'] ?? optional($draft->day)->toDateString(),
                    'location' => $record['venue'] ?? '—',
                    'status' => $record['status'] ?? 'no_response',
                ];
            })
            ->filter()
            ->values();
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

    private function attendanceEntriesForPeriod(Student $student, Carbon $start, Carbon $end): array
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $entries = [];

        $student->attendanceRecords()
            ->whereBetween('day', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->get(['gathering_type', 'day', 'status'])
            ->each(function (AttendanceRecord $record) use (&$entries) {
                $date = $record->day->toDateString();
                $entries[$record->gathering_type . '|' . $date] = [
                    'gathering_type' => $record->gathering_type,
                    'date' => $date,
                    'status' => $record->status,
                ];
            });

        $draftEntries = [];
        AttendanceDraft::query()
            ->where('status', 'draft')
            ->whereBetween('day', [$startDate, $endDate])
            ->orderByDesc('updated_at')
            ->get(['gathering_type', 'day', 'parsed_data'])
            ->each(function ($draft) use ($student, &$entries, &$draftEntries, $startDate, $endDate) {
                foreach ($draft->parsed_data ?? [] as $record) {
                    if ((int) ($record['student_id'] ?? 0) !== $student->id) {
                        continue;
                    }

                    $date = $record['day'] ?? optional($draft->day)->toDateString();
                    if (! $date || $date < $startDate || $date > $endDate) {
                        continue;
                    }

                    $type = $draft->gathering_type;
                    $key = $type . '|' . $date;
                    if (isset($draftEntries[$key])) {
                        continue;
                    }
                    $draftEntries[$key] = true;
                    $entries[$key] = [
                        'gathering_type' => $type,
                        'date' => $date,
                        'status' => $record['status'] ?? 'no_response',
                    ];
                }
            });

        return array_values($entries);
    }
}
