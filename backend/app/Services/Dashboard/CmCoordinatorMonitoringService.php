<?php

namespace App\Services\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Program;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CmCoordinatorMonitoringService
{
    private const GATHERING_TYPES = ['pm', 'ws', 'tg'];

    private const GATHERING_LABELS = [
        'pm' => 'Prayer Meeting',
        'ws' => 'Worship Service',
        'tg' => 'Thanksgiving',
    ];

    public function getPayload(array $filters): array
    {
        $month = Carbon::create(
            $filters['year'] ?? now()->year,
            $filters['month'] ?? now()->month,
            1
        )->startOfMonth();

        $programs = Program::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        // Include legacy student programs that may not yet have a programs row.
        $knownCodes = $programs->pluck('code')->map(fn ($code) => strtoupper(trim($code)));
        $legacyCodes = Student::query()
            ->whereNotNull('program')
            ->where('program', '!=', '')
            ->distinct()
            ->orderBy('program')
            ->pluck('program')
            ->filter(fn ($code) => ! $knownCodes->contains(strtoupper(trim($code))));

        $programOptions = $programs->map(fn (Program $program) => [
            'id' => $program->id,
            'code' => $program->code,
            'name' => $program->name ?: $program->code,
        ])->concat($legacyCodes->map(fn ($code) => [
            'id' => null,
            'code' => $code,
            'name' => $code,
        ]))->values();
        $programOptions = $programOptions->map(fn ($program) => (array) $program);

        if (! empty($filters['program_id'])) {
            $selectedCode = $programs->firstWhere('id', (int) $filters['program_id'])?->code;
            $programOptions = $programOptions->filter(fn ($program) => $program['code'] === $selectedCode)->values();
        }

        $courses = $this->getCourses($programOptions, $month, $filters);
        $weeks = $this->getWeeks($month);
        $weekFilter = $filters['week'] ?? 'all';
        $gatheringFilter = $filters['gathering_type'] ?? 'all';

        $visibleWeeks = $weekFilter === 'all'
            ? $weeks
            : $weeks->filter(fn ($week) => $week['number'] === (int) $weekFilter)->values();

        $visibleGatherings = $gatheringFilter === 'all'
            ? self::GATHERING_TYPES
            : [$gatheringFilter];

        $reportableSlots = $courses->sum(fn ($course) => data_get($course, 'progress.total', 0));
        $completedSlots = $courses->sum(fn ($course) => data_get($course, 'progress.completed', 0));

        return [
            'role' => 'cm_coordinator',
            'month' => [
                'month' => $month->month,
                'year' => $month->year,
                'label' => $month->format('F Y'),
                'start' => $month->toDateString(),
                'end' => $month->copy()->endOfMonth()->toDateString(),
            ],
            'filters' => [
                'program_id' => $filters['program_id'] ?? null,
                'week' => $weekFilter,
                'gathering_type' => $gatheringFilter,
                'search' => $filters['search'] ?? '',
            ],
            'programs' => $programOptions,
            'weeks' => $visibleWeeks,
            'gathering_types' => self::GATHERING_TYPES,
            'summary' => [
                'completed_attendance' => $completedSlots,
                'total_attendance' => $reportableSlots,
                'completion_percentage' => $reportableSlots > 0
                    ? (int) round(($completedSlots / $reportableSlots) * 100)
                    : 0,
            ],
            'courses' => $courses->map(function ($course) use ($visibleWeeks, $visibleGatherings) {
                $course = (array) $course;
                $course['weeks'] = collect($course['weeks'] ?? [])
                    ->map(fn ($week) => (array) $week)
                    ->filter(fn ($week) => $visibleWeeks->contains('number', $week['number'] ?? null))
                    ->map(function ($week) use ($visibleGatherings) {
                        $week['gatherings'] = collect($week['gatherings'] ?? [])
                            ->map(fn ($gathering) => (array) $gathering)
                            ->filter(fn ($gathering) => in_array($gathering['type'] ?? null, $visibleGatherings, true))
                            ->values();
                        return $week;
                    })
                    ->values();
                return $course;
            })->values(),
        ];
    }

    public function getCourseDetails(string $programCode, int $yearLevel, array $filters): ?array
    {
        $month = Carbon::create(
            $filters['year'] ?? now()->year,
            $filters['month'] ?? now()->month,
            1
        )->startOfMonth();

        $programCode = strtoupper(trim($programCode));
        $roster = Student::query()
            ->whereRaw('UPPER(TRIM(program)) = ?', [$programCode])
            ->where('year_level', $yearLevel)
            ->orderBy('name')
            ->get(['id', 'name', 'program', 'year_level']);

        if ($roster->isEmpty()) {
            return null;
        }

        $selectedWeek = (int) ($filters['week'] ?? 1);
        $week = $this->getWeeks($month)->firstWhere('number', $selectedWeek);

        if (! $week) {
            return null;
        }

        $records = AttendanceRecord::query()
            ->with(['validator:id,name'])
            ->whereIn('student_id', $roster->pluck('id'))
            ->whereIn('gathering_type', self::GATHERING_TYPES)
            ->whereBetween('day', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderBy('day')
            ->get();

        $weekRecords = $records->filter(fn (AttendanceRecord $record) =>
            $record->day->toDateString() >= $week['start']
            && $record->day->toDateString() <= $week['end']
        );

        $entries = $weekRecords
            ->groupBy(fn (AttendanceRecord $record) => $record->gathering_type . '|' . $record->day->toDateString())
            ->map(function ($eventRecords, string $eventKey) use ($roster) {
                [$type, $date] = explode('|', $eventKey, 2);
                $recordedStudentIds = $eventRecords->pluck('student_id')->unique();
                $missingStudents = $roster
                    ->reject(fn (Student $student) => $recordedStudentIds->contains($student->id))
                    ->map(fn (Student $student) => [
                        'id' => $student->id,
                        'name' => $student->name,
                    ])->values();

                $allValidated = $eventRecords->every(fn (AttendanceRecord $record) =>
                    in_array($record->validation_status, ['validated', 'approved'], true)
                );
                $latestApproval = $eventRecords
                    ->filter(fn (AttendanceRecord $record) => in_array($record->validation_status, ['validated', 'approved'], true))
                    ->sortByDesc('updated_at')
                    ->first();

                return [
                    'gathering_type' => $type,
                    'gathering' => self::GATHERING_LABELS[$type] ?? ucfirst($type),
                    'date' => $date,
                    'status' => $missingStudents->isEmpty() ? 'completed' : 'incomplete',
                    'approval_status' => $allValidated ? 'approved' : 'pending',
                    'approved_on' => $allValidated ? $latestApproval?->updated_at?->toIso8601String() : null,
                    'program_head' => $allValidated
                        ? $latestApproval?->validator?->name
                        : null,
                    'recorded_students' => $recordedStudentIds->count(),
                    'total_students' => $roster->count(),
                    'remarks' => $recordedStudentIds->count() . '/' . $roster->count(),
                    'missing_students' => $missingStudents,
                ];
            })
            ->sortBy(fn (array $entry) => $entry['date'] . '|' . $entry['gathering_type'])
            ->values();

        $program = Program::query()->whereRaw('UPPER(TRIM(code)) = ?', [$programCode])->first();
        $latestApproval = $records
            ->filter(fn (AttendanceRecord $record) => in_array($record->validation_status, ['validated', 'approved'], true))
            ->sortByDesc('updated_at')
            ->first();
        $completedEvents = $records
            ->groupBy(fn (AttendanceRecord $record) => $record->gathering_type . '|' . $record->day->toDateString())
            ->filter(fn ($eventRecords) => $eventRecords->pluck('student_id')->unique()->count() >= $roster->count())
            ->count();
        $monthlyEventTotal = $this->getWeeks($month)->count() * count(self::GATHERING_TYPES);

        return [
            'course' => [
                'program' => $programCode,
                'program_name' => $program?->name ?: $programCode,
                'year_level' => $yearLevel,
                'label' => $programCode . ' ' . $yearLevel,
                'students_count' => $roster->count(),
            ],
            'month' => [
                'month' => $month->month,
                'year' => $month->year,
                'label' => $month->format('F Y'),
            ],
            'progress' => [
                'completed' => $completedEvents,
                'total' => $monthlyEventTotal,
                'percentage' => (int) round(($completedEvents / max(1, $monthlyEventTotal)) * 100),
            ],
            'approval' => [
                'approved_on' => $latestApproval?->updated_at?->toIso8601String(),
                'program_head' => $latestApproval?->validator?->name,
            ],
            'week' => $week,
            'week_details' => $entries,
            'incomplete_entries' => $entries
                ->where('status', 'incomplete')
                ->values(),
        ];
    }

    private function getCourses(Collection $programs, Carbon $month, array $filters): Collection
    {
        $programCodes = $programs->pluck('code')->all();
        $students = Student::query()
            ->whereIn('program', $programCodes)
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $search = trim($filters['search']);
                $query->where(function ($studentQuery) use ($search) {
                    $studentQuery->where('program', 'like', "%{$search}%")
                        ->orWhere('year_level', 'like', "%{$search}%");
                });
            })
            ->orderBy('program')
            ->orderBy('year_level')
            ->get(['id', 'program', 'year_level'])
            ->groupBy(fn (Student $student) => strtoupper(trim($student->program)) . '|' . $student->year_level);

        $weeks = $this->getWeeks($month);
        $records = AttendanceRecord::query()
            ->with('student:id,program,year_level')
            ->whereIn('gathering_type', self::GATHERING_TYPES)
            ->whereBetween('day', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->whereHas('student', fn ($query) => $query->whereIn('program', $programCodes))
            ->get();

        return $students->map(function (Collection $roster, string $key) use ($records, $weeks) {
            [$programCode, $yearLevel] = explode('|', $key, 2);
            $studentIds = $roster->pluck('id')->all();
            $courseRecords = $records->filter(fn (AttendanceRecord $record) =>
                strtoupper(trim((string) $record->student?->program)) === $programCode
                && (string) $record->student?->year_level === (string) $yearLevel
            );

            $weeklyData = $weeks->map(function (array $week) use ($courseRecords, $studentIds) {
                $recordsForWeek = $courseRecords->filter(fn (AttendanceRecord $record) =>
                    $record->day->toDateString() >= $week['start']
                    && $record->day->toDateString() <= $week['end']
                );

                $gatherings = collect(self::GATHERING_TYPES)->map(function (string $type) use ($recordsForWeek, $studentIds) {
                    $submittedStudentCount = $recordsForWeek
                        ->where('gathering_type', $type)
                        ->pluck('student_id')
                        ->unique()
                        ->count();

                    $status = match (true) {
                        $submittedStudentCount === 0 => 'no_report',
                        $submittedStudentCount >= count($studentIds) => 'completed',
                        default => 'incomplete',
                    };

                    return [
                        'type' => $type,
                        'status' => $status,
                        'completed_students' => min($submittedStudentCount, count($studentIds)),
                        'total_students' => count($studentIds),
                    ];
                })->values();

                $completedCount = $gatherings->where('status', 'completed')->count();
                $reportedCount = $gatherings->whereIn('status', ['completed', 'incomplete'])->count();

                return [
                    'number' => $week['number'],
                    'label' => $week['label'],
                    'start' => $week['start'],
                    'end' => $week['end'],
                    'status' => $completedCount === 3
                        ? 'completed'
                        : ($reportedCount > 0 ? 'incomplete' : 'no_report'),
                    'gatherings' => $gatherings,
                ];
            });

            $completedCount = $weeklyData->flatMap(fn ($week) => $week['gatherings'])
                ->where('status', 'completed')
                ->count();
            $totalCount = $weeks->count() * count(self::GATHERING_TYPES);

            return [
                'program' => $programCode,
                'year_level' => $yearLevel,
                'label' => $programCode . ' ' . $yearLevel,
                'students_count' => count($studentIds),
                'progress' => [
                    'completed' => $completedCount,
                    'total' => $totalCount,
                    'percentage' => $totalCount > 0 ? (int) round(($completedCount / $totalCount) * 100) : 0,
                ],
                'weeks' => $weeklyData->values()->all(),
            ];
        })->map(fn ($course) => (array) $course)->values();
    }

    private function getWeeks(Carbon $month): Collection
    {
        $daysInMonth = $month->daysInMonth;
        $weekCount = (int) ceil($daysInMonth / 7);

        return collect(range(1, $weekCount))->map(function (int $number) use ($month, $daysInMonth) {
            $startDay = (($number - 1) * 7) + 1;
            $endDay = min($number * 7, $daysInMonth);
            $ordinal = match ($number) {
                1 => '1st', 2 => '2nd', 3 => '3rd', default => $number . 'th',
            };

            return [
                'number' => $number,
                'label' => "Week {$number}",
                'description' => "{$ordinal} week of {$month->format('F')}",
                'start' => $month->copy()->day($startDay)->toDateString(),
                'end' => $month->copy()->day($endDay)->toDateString(),
            ];
        });
    }
}
