<?php

namespace App\Services\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\AttendanceDraft;
use App\Models\Program;
use App\Models\Student;
use App\Support\GatheringCycle;
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

        $selectedCode = ! empty($filters['program_id'])
            ? $programs->firstWhere('id', (int) $filters['program_id'])?->code
            : ($filters['program_code'] ?? null);

        if ($selectedCode !== null) {
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
                'program_code' => $filters['program_code'] ?? null,
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
        $program = Program::query()->whereRaw('UPPER(TRIM(code)) = ?', [$programCode])->first();
        $programHeadNames = $program
            ? $program->programHeads()
                ->where('users.is_active', true)
                ->orderBy('users.name')
                ->pluck('users.name')
                ->implode(', ')
            : null;
        $maximumYearLevel = $programCode === 'ACT' ? 2 : 4;

        if ($yearLevel < 1 || $yearLevel > $maximumYearLevel) {
            return null;
        }

        $roster = Student::query()
            ->active()
            ->whereRaw('UPPER(TRIM(program)) = ?', [$programCode])
            ->where('year_level', $yearLevel)
            ->orderBy('name')
            ->get(['id', 'name', 'program', 'year_level']);

        if ($program === null && $roster->isEmpty()) {
            return null;
        }

        $weeks = $this->getWeeks($month);
        $rangeStart = $weeks->first()['start'];
        $rangeEnd = $weeks->last()['end'];
        $selectedWeek = (int) ($filters['week'] ?? 1);
        $week = $weeks->firstWhere('number', $selectedWeek);

        if (! $week) {
            return null;
        }

        $records = AttendanceRecord::query()
            ->with(['validator:id,name'])
            ->whereIn('student_id', $roster->pluck('id'))
            ->whereIn('gathering_type', self::GATHERING_TYPES)
            ->inReportingRange([$rangeStart, $rangeEnd])
            ->orderBy('day')
            ->get();
        $records = $records->concat($this->getDraftAttendanceRows(
            $rangeStart,
            $rangeEnd,
            $roster->pluck('id')->all()
        ));

        $weekRecords = $records->filter(fn ($record) =>
            ($record->reporting_day ?? $record->day)->toDateString() >= $week['start']
            && ($record->reporting_day ?? $record->day)->toDateString() <= $week['end']
        );

        $studentIds = $roster->pluck('id')->all();
        $entries = collect(self::GATHERING_TYPES)
            ->map(function (string $type) use ($weekRecords, $roster, $studentIds, $week) {
                $gatheringRange = $week['gathering_ranges'][$type];
                $eventRecords = $weekRecords->where('gathering_type', $type)
                    ->filter(fn ($record) => ($record->reporting_day ?? $record->day)->toDateString() >= $gatheringRange['start']
                        && ($record->reporting_day ?? $record->day)->toDateString() <= $gatheringRange['end']);
                $summary = $this->getGatheringSummary($weekRecords, $studentIds, $type, $gatheringRange);
                $dates = $eventRecords->pluck('day')
                    ->map(fn (Carbon $day) => $day->toDateString())
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $respondedStudentIds = $eventRecords
                    ->filter(fn ($record) => in_array($record->status ?? 'no_response', ['present', 'excused'], true))
                    ->pluck('student_id')
                    ->unique();
                $missingStudents = $roster
                    ->reject(fn (Student $student) => $respondedStudentIds->contains($student->id))
                    ->map(fn (Student $student) => [
                        'id' => $student->id,
                        'name' => $student->name,
                    ])->values();

                $allValidated = $eventRecords->isNotEmpty() && $eventRecords->every(fn ($record) =>
                    in_array($record->validation_status, ['validated', 'approved'], true)
                );
                $latestApproval = $eventRecords
                    ->filter(fn ($record) => in_array($record->validation_status, ['validated', 'approved'], true))
                    ->sortByDesc('updated_at')
                    ->first();

                return [
                    'gathering_type' => $type,
                    'gathering' => self::GATHERING_LABELS[$type] ?? ucfirst($type),
                    'date' => $dates[0] ?? null,
                    'dates' => $dates,
                    'status' => $summary['status'],
                    'approval_status' => $allValidated ? 'approved' : 'pending',
                    'approved_on' => $allValidated ? $latestApproval?->updated_at?->toIso8601String() : null,
                    'program_head' => $allValidated
                        ? $latestApproval?->validator?->name
                        : null,
                    'recorded_students' => $summary['responded_students'],
                    'responded_students' => $summary['responded_students'],
                    'total_students' => $summary['total_students'],
                    'remarks' => $summary['responded_students'] . '/' . $summary['total_students'],
                    'missing_students' => $missingStudents,
                ];
            })
            ->sortBy(fn (array $entry) => ($entry['date'] ?? '9999-12-31') . '|' . $entry['gathering_type'])
            ->values();
        $latestApproval = $records
            ->filter(fn ($record) => in_array($record->validation_status, ['validated', 'approved'], true))
            ->sortByDesc('updated_at')
            ->first();
        $completedEvents = $weeks->sum(function (array $monthWeek) use ($records, $studentIds) {
            $recordsForWeek = $records->filter(fn ($record) =>
                ($record->reporting_day ?? $record->day)->toDateString() >= $monthWeek['start']
                && ($record->reporting_day ?? $record->day)->toDateString() <= $monthWeek['end']
            );

            return collect(self::GATHERING_TYPES)
                ->filter(fn (string $type) => $this->getGatheringSummary(
                    $recordsForWeek,
                    $studentIds,
                    $type,
                    $monthWeek['gathering_ranges'][$type]
                )['status'] === 'completed')
                ->count();
        });
        $monthlyEventTotal = $weeks->count() * count(self::GATHERING_TYPES);

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
                'program_head' => $programHeadNames ?: null,
            ],
            'week' => $week,
            'week_details' => $entries,
            'incomplete_entries' => $entries->where('status', 'incomplete')->values(),
        ];
    }

    private function getCourses(Collection $programs, Carbon $month, array $filters): Collection
    {
        $programCodes = $programs->pluck('code')->all();
        $search = trim((string) ($filters['search'] ?? ''));
        $matchingProgramCodes = $search === ''
            ? []
            : $programs->filter(fn ($program) =>
                str_contains(mb_strtolower((string) $program['code']), mb_strtolower($search))
                || str_contains(mb_strtolower((string) $program['name']), mb_strtolower($search))
            )->pluck('code')->all();
        $students = Student::query()
            ->active()
            ->whereIn('program', $programCodes)
            ->when($search !== '', function ($query) use ($search, $matchingProgramCodes) {
                $query->where(function ($studentQuery) use ($search, $matchingProgramCodes) {
                    $studentQuery->where('program', 'like', "%{$search}%")
                        ->orWhere('year_level', 'like', "%{$search}%");
                    if ($matchingProgramCodes !== []) {
                        $studentQuery->orWhereIn('program', $matchingProgramCodes);
                    }
                });
            })
            ->orderBy('program')
            ->orderBy('year_level')
            ->get(['id', 'program', 'year_level'])
            ->groupBy(fn (Student $student) => strtoupper(trim($student->program)) . '|' . $student->year_level);

        if (empty($filters['search'])) {
            foreach ($programs as $program) {
                $programCode = strtoupper(trim($program['code']));
                $yearCount = $programCode === 'ACT' ? 2 : 4;

                for ($yearLevel = 1; $yearLevel <= $yearCount; $yearLevel++) {
                    $key = $programCode . '|' . $yearLevel;
                    if (! $students->has($key)) {
                        $students->put($key, collect());
                    }
                }
            }

            $students = $students->sortKeys();
        }

        $weeks = $this->getWeeks($month);
        $rangeStart = $weeks->first()['start'];
        $rangeEnd = $weeks->last()['end'];
        $records = AttendanceRecord::query()
            ->with('student:id,program,year_level')
            ->whereIn('gathering_type', self::GATHERING_TYPES)
            ->inReportingRange([$rangeStart, $rangeEnd])
            ->whereHas('student', fn ($query) => $query->whereIn('program', $programCodes))
            ->get();
        $records = $records->concat($this->getDraftAttendanceRows(
            $rangeStart,
            $rangeEnd
        ));

        return $students->map(function (Collection $roster, string $key) use ($records, $weeks) {
            [$programCode, $yearLevel] = explode('|', $key, 2);
            $studentIds = $roster->pluck('id')->all();
            $courseRecords = $records->filter(fn ($record) =>
                strtoupper(trim((string) ($record->program ?? $record->student?->program))) === $programCode
                && (string) $record->student?->year_level === (string) $yearLevel
            );

            $weeklyData = $weeks->map(function (array $week) use ($courseRecords, $studentIds) {
                $recordsForWeek = $courseRecords->filter(fn ($record) =>
                    ($record->reporting_day ?? $record->day)->toDateString() >= $week['start']
                    && ($record->reporting_day ?? $record->day)->toDateString() <= $week['end']
                );

                $gatherings = collect(self::GATHERING_TYPES)->map(function (string $type) use ($recordsForWeek, $studentIds, $week) {
                    $gatheringRange = $week['gathering_ranges'][$type];
                    return [
                        'type' => $type,
                        ...$this->getGatheringSummary($recordsForWeek, $studentIds, $type, $gatheringRange),
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

    private function getGatheringSummary(Collection $weekRecords, array $studentIds, string $type, ?array $gatheringRange = null): array
    {
        return app(AttendanceResponseSummary::class)->gathering($weekRecords, $studentIds, $type, $gatheringRange);
    }

    private function getDraftAttendanceRows(string $start, string $end, ?array $studentIds = null): Collection
    {
        return AttendanceDraft::query()
            ->whereIn('status', ['draft', 'pending'])
            ->whereBetween('day', [$start, $end])
            ->get()
            ->flatMap(function (AttendanceDraft $draft) use ($studentIds) {
                return collect($draft->parsed_data ?? [])
                    ->filter(fn (array $entry) => ! empty($entry['student_id'])
                        && ($studentIds === null || in_array((int) $entry['student_id'], $studentIds, true)))
                    ->map(fn (array $entry) => (object) [
                        'student_id' => (int) $entry['student_id'],
                        'student' => (object) [
                            'program' => $entry['program'] ?? null,
                            'year_level' => $entry['year_level'] ?? null,
                        ],
                        'gathering_type' => $entry['gathering_type'] ?? $draft->gathering_type,
                        'day' => Carbon::parse($entry['day'] ?? $draft->day),
                        'reporting_day' => Carbon::parse($draft->day),
                        'status' => $entry['status'] ?? 'no_response',
                        'validation_status' => 'unvalidated',
                        'validator' => null,
                        'updated_at' => $draft->updated_at,
                    ]);
            })
            ->values();
    }

    private function getWeeks(Carbon $month): Collection
    {
        $weekStart = GatheringCycle::weekStartForDate($month);
        $lastWeekStart = GatheringCycle::weekStartForDate($month->copy()->endOfMonth());
        $weeks = collect();

        for ($number = 1; $weekStart->lessThanOrEqualTo($lastWeekStart); $number++, $weekStart->addWeek()) {
            $weekEnd = $weekStart->copy()->addDays(6);
            $gatheringRanges = collect(GatheringCycle::getRangesForWeekStart($weekStart))
                ->map(fn (array $range) => [
                    'start' => $range['start']->toDateString(),
                    'end' => $range['end']->toDateString(),
                ])->all();

            $weeks->push([
                'number' => $number,
                'label' => "Week {$number}",
                'description' => $weekStart->format('M j') . ' – ' . $weekEnd->format(
                    $weekStart->isSameMonth($weekEnd) ? 'j, Y' : 'M j, Y'
                ),
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'gathering_ranges' => $gatheringRanges,
            ]);
        }

        return $weeks;
    }
}
