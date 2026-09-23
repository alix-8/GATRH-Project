<?php

namespace App\Services;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{

    //For Secretaries
    public function getGeneralDashboardPayload($user, array $filters): array
    {
        $dateRange = $this->resolveDateRange($filters['week'] ?? 'all');

        return [
            'summary'        => $this->getAttendanceSummary($user, $dateRange),
            'chart_data'     => $this->getAttendanceChartData($user, $filters['week'] ?? 'this_week', $dateRange),
            'pending_list'   => $this->getPendingAttendance($user),
            'recent_records' => $this->getRecentRecords($user, $dateRange),
            'monthly_gathering' => $this->getMonthlyGatheringData($user, $filters['month'], $filters['year']),
            'calendar_marks' => $this->getCalendarMarks($user, $filters['month'], $filters['year']),
            'role'           => 'secretary',
        ];
    }

    //For Program Heads
    public function getProgramHeadDashboardPayload($user, array $filters): array
    {
        $dateRange = $this->resolveDateRange($filters['week'] ?? 'all');

        return [
            'summary'        => $this->getProgramHeadSummary($user, $dateRange),
            'pending_list'   => $this->getProgramHeadPendingList($user, $dateRange),
            'recent_records' => $this->getProgramHeadRecentActivity($user, $dateRange),
            'calendar_marks' => $this->getCalendarMarks($user, $filters['month'], $filters['year']),
            'role'           => 'program_head',
        ];
    }

    public function getChancellorDashboardPayload($user, array $filters): array
    {
        $selectedDate = Carbon::parse($filters['date'] ?? now());
        $dateRange = $this->resolveDateRange($filters['week'] ?? 'this_week');

        // List of programs comes from students.program.
        $programs = Student::query()
            ->select('program')
            ->whereNotNull('program')
            ->where('program', '!=', '')
            ->distinct()
            ->orderBy('program')
            ->pluck('program');

        // Records for the currently selected reporting period.
        $records = AttendanceRecord::query()
            ->with('student:id,program')
            ->whereBetween('day', [
                $dateRange['start'],
                $dateRange['end'],
            ])
            ->get();

        // Records for the four weeks shown in the dashboard table/chart.
        $weekFourStart = $selectedDate->copy()
            ->startOfWeek()
            ->subWeeks(3);

        $weekFourEnd = $selectedDate->copy()
            ->endOfWeek();

        $fourWeekRecords = AttendanceRecord::query()
            ->with('student:id,program')
            ->whereBetween('day', [
                $weekFourStart,
                $weekFourEnd,
            ])
            ->get();

        // Reusable rule:
        // 3 gathering types = complete
        // 1–2 gathering types = incomplete
        // 0 gathering types = no report
        $getProgramStatus = function ($programCode, $programRecords): string {
            $gatheringCount = $programRecords
                ->filter(
                    fn ($record) => $record->student?->program === $programCode
                )
                ->pluck('gathering_type')
                ->filter()
                ->unique()
                ->count();

            return match (true) {
                $gatheringCount >= 3 => 'completed',
                $gatheringCount > 0 => 'incomplete',
                default => 'no_report',
            };
        };

        $getCompletionPercentage = function ($programCode, $programRecords): int {
            $gatheringCount = $programRecords
                ->filter(
                    fn ($record) => $record->student?->program === $programCode
                )
                ->pluck('gathering_type')
                ->filter()
                ->unique()
                ->count();

            return min(100, (int) round(($gatheringCount / 3) * 100));
        };

        // Data for the Programs Needing Attention table
        // and Program Completion Overview.
        $programReporting = $programs->map(
            function ($programCode) use (
                $records,
                $fourWeekRecords,
                $selectedDate,
                $getProgramStatus,
                $getCompletionPercentage
            ) {
                $weeklyStatuses = collect(range(3, 0))
                    ->map(function ($weekOffset) use (
                        $programCode,
                        $fourWeekRecords,
                        $selectedDate,
                        $getProgramStatus
                    ) {
                        $weekStart = $selectedDate->copy()
                            ->startOfWeek()
                            ->subWeeks($weekOffset);

                        $weekEnd = $weekStart->copy()->endOfWeek();

                        $recordsForWeek = $fourWeekRecords->filter(
                            fn ($record) => $record->day->betweenIncluded(
                                $weekStart,
                                $weekEnd
                            )
                        );

                        return $getProgramStatus($programCode, $recordsForWeek);
                    })
                    ->values()
                    ->toArray();

                return [
                    'code' => $programCode,
                    'status' => $getProgramStatus($programCode, $records),
                    'completion' => $getCompletionPercentage(
                        $programCode,
                        $records
                    ),

                    // For table columns: Week 1, Week 2, Week 3, Week 4
                    'weekly_statuses' => $weeklyStatuses,
                ];
            }
        )->values();

        $completed = $programReporting
            ->where('status', 'completed')
            ->count();

        $incomplete = $programReporting
            ->where('status', 'incomplete')
            ->count();

        $noReport = $programReporting
            ->where('status', 'no_report')
            ->count();

        $totalPrograms = $programReporting->count();

        $completionRate = $totalPrograms > 0
            ? (int) round(($completed / $totalPrograms) * 100)
            : 0;

        // Data for the Progress by Week stacked bar chart.
        $weeklyProgress = collect(range(3, 0))
            ->map(function ($weekOffset) use (
                $selectedDate,
                $fourWeekRecords,
                $programs,
                $getProgramStatus
            ) {
                $weekStart = $selectedDate->copy()
                    ->startOfWeek()
                    ->subWeeks($weekOffset);

                $weekEnd = $weekStart->copy()->endOfWeek();

                $recordsForWeek = $fourWeekRecords->filter(
                    fn ($record) => $record->day->betweenIncluded(
                        $weekStart,
                        $weekEnd
                    )
                );

                $statuses = $programs->map(
                    fn ($programCode) => $getProgramStatus(
                        $programCode,
                        $recordsForWeek
                    )
                );

                return [
                    'label' => 'Week ' . (4 - $weekOffset),
                    'week_start' => $weekStart->toDateString(),
                    'week_end' => $weekEnd->toDateString(),
                    'completed' => $statuses
                        ->filter(fn ($status) => $status === 'completed')
                        ->count(),
                    'incomplete' => $statuses
                        ->filter(fn ($status) => $status === 'incomplete')
                        ->count(),
                    'no_report' => $statuses
                        ->filter(fn ($status) => $status === 'no_report')
                        ->count(),
                ];
            })
            ->values();

        return [
            'role' => 'chancellor',

            // Four cards at the top
            'summary' => [
                'completed' => $completed,
                'incomplete' => $incomplete,
                'no_report' => $noReport,
                'total_programs' => $totalPrograms,
                'completion_rate' => $completionRate,
            ],

            // Overall Reporting Progress card
            'overall_progress' => [
                'percentage' => $completionRate,
                'completed_programs' => $completed,
                'total_programs' => $totalPrograms,
                'tally' => "{$completed} of {$totalPrograms} programs completed",
            ],

            // Programs Needing Attention table
            // plus the Program Completion Overview list
            'program_reporting' => $programReporting,

            // Progress by Week chart
            'weekly_progress' => $weeklyProgress,
        ];
    }

    public function getProgramHeadSummary($user, array $dateRange): array
    {
        $baseQuery = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']]);

        $pendingReview = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereNull('validation_status')
                    ->orWhere('validation_status', 'unvalidated');
            })
            ->count();

        $approved = (clone $baseQuery)->where('validation_status', 'approved')->count();
        $rejected = (clone $baseQuery)->where('validation_status', 'rejected')->count();
        $total = (clone $baseQuery)->count();

        return [
            'pending_review' => $pendingReview,
            'approved' => $approved,
            'rejected' => $rejected,
            'total_submissions' => $total,
        ];
    }

    public function getAttendanceSummary($user, array $dateRange): array
    {
        $baseQuery = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']]);

        $presents = (clone $baseQuery)->where('status', 'present')->count();
        $absents = (clone $baseQuery)->where('status', 'no_response')->count();

        return [
            'total_presents' => $presents,
            'total_absents' => $absents,
            'total_records' => $presents + $absents,
        ];
    }

    public function getAttendanceChartData($user, string $filter, array $dateRange): array
    {
        $records = AttendanceRecord::query()
            ->select('gathering_type', 'status', DB::raw('COUNT(*) as total'))
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->groupBy('gathering_type', 'status')
            ->get();

        $categories = ['pm', 'ws', 'tg'];
        $chartData = [];

        foreach ($categories as $cat) {
            $presentCount = (int) ($records->where('gathering_type', $cat)->where('status', 'present')->first()->total ?? 0);
            $absentCount = (int) ($records->where('gathering_type', $cat)->where('status', 'no_response')->first()->total ?? 0);

            $chartData[] = [
                'category' => strtoupper($cat),
                'present' => $presentCount,
                'absent' => $absentCount,
            ];
        }

        return [
            'filter_applied' => $filter,
            'series' => $chartData,
        ];
    }

    public function getPendingAttendance($user): array
    {
        return AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($draft) use ($user) {
                $parsed = is_array($draft->parsed_data) ? $draft->parsed_data : (array) $draft->parsed_data;
                $firstRecord = collect($parsed)->first();
                $gatheringType = $firstRecord['gathering_type'] ?? null;

                return [
                    'id' => $draft->id,
                    'program_label' => $firstRecord['program'] ?? $user->program ?? 'Attendance Draft',
                    'year_level' => $firstRecord['year_level'] ?? $user->year_level,
                    'gathering' => $this->formatGatheringName($gatheringType ?? 'pm'),
                    'submitted_ago' => $draft->created_at->diffForHumans(),
                ];
            })
            ->toArray();
    }

    public function getProgramHeadPendingList($user, array $dateRange): array
    {
        $records = AttendanceRecord::query()
            ->with(['student', 'submitter'])
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->where(function ($query) {
                $query->whereNull('validation_status')
                    ->orWhere('validation_status', 'unvalidated');
            })
            ->orderBy('day', 'desc')
            ->get();

        return $records
            ->groupBy(fn ($r) => $r->batch_id ?? ($r->gathering_type . '|' . $r->day . '|' . $r->submitted_by))
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'id'           => $first->batch_id ?? $first->id,
                    'secretary'    => $first->submitter?->name ?? 'Unknown Secretary',
                    'gathering'    => $this->formatGatheringName($first->gathering_type),
                    'program'      => $first->student?->program ?? 'N/A',
                    'year_level'   => $first->student?->year_level ?? 'N/A',
                    'date'         => Carbon::parse($first->day)->format('M d, Y'),
                    'student_count'=> $group->count(),
                    'status'       => 'Pending',
                    'status_label' => 'Pending',
                    'action'       => 'Review',
                    'review'       => true,
                ];
            })
            ->take(10)
            ->values()
            ->toArray();
    }

    public function getRecentRecords($user, array $dateRange): array
    {
        $totalManagedStudents = Student::query()->accessibleTo($user)->count();

        $attendances = AttendanceRecord::query()
            ->select(
                'gathering_type',
                'day',
                'validation_status',
                DB::raw("COUNT(DISTINCT CASE WHEN status = 'present' THEN student_id END) as present_count")
            )
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->groupBy('gathering_type', 'day', 'validation_status')
            ->orderBy('day', 'desc')
            ->limit(5)
            ->get();

        return $attendances->map(function ($row) use ($totalManagedStudents) {
            $status = $row->validation_status ?? 'pending';

            return [
                'gathering' => $this->formatGatheringName($row->gathering_type),
                'date' => Carbon::parse($row->day)->format('F j, Y'),
                'students' => "{$row->present_count}/{$totalManagedStudents}",
                'status' => $status,
                'status_label' => match ($status) {
                    'approved', 'validated' => 'Approved',
                    'rejected' => 'Rejected',
                    default => 'Pending',
                },
            ];
        })->toArray();
    }

    public function getMonthlyGatheringData($user, int $monthNumber, int $year): array
    {
        $month = Carbon::create($year, $monthNumber, 1)->startOfDay();
        $monthStart = $month->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();
        $totalManagedStudents = Student::query()->accessibleTo($user)->count();

        $records = AttendanceRecord::query()
            ->select('gathering_type', DB::raw("COUNT(DISTINCT CASE WHEN status = 'present' THEN student_id END) as present_count"))
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$monthStart, $monthEnd])
            ->groupBy('gathering_type')
            ->get()
            ->keyBy('gathering_type');

        return collect(['pm', 'ws', 'tg'])
            ->map(function ($type) use ($records, $totalManagedStudents) {
                $presentCount = (int) ($records->get($type)?->present_count ?? 0);
                $percentage = $totalManagedStudents > 0
                    ? min(100, (int) round(($presentCount / $totalManagedStudents) * 100))
                    : 0;

                return [
                    'gathering' => $this->formatGatheringName($type),
                    'percentage' => $percentage,
                ];
            })
            ->values()
            ->toArray();
    }

    public function getProgramHeadRecentActivity($user, array $dateRange): array
    {
        $records = AttendanceRecord::query()
            ->with(['student', 'submitter'])
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->orderBy('updated_at', 'desc')
            ->get();

        return $records
            ->groupBy(fn ($r) => $r->batch_id ?? ($r->gathering_type . '|' . $r->day . '|' . $r->submitted_by))
            ->sortByDesc(fn ($group) => $group->first()->updated_at)
            ->map(function ($group) {
                $first = $group->first();
                $status = $first->validation_status ?? 'unvalidated';
                $gathering = $this->formatGatheringName($first->gathering_type);
                $secretary = $first->submitter?->name ?? 'Unknown';

                [$title, $description, $iconType] = match ($status) {
                    'validated' => [
                        "{$gathering} attendance submitted",
                        "you approved the record",
                        'approved',
                    ],
                    'rejected' => [
                        "{$gathering} Attendance rejected",
                        $secretary,
                        'rejected',
                    ],
                    default => $first->created_at->ne($first->updated_at)
                        ? ["{$gathering} attendance resubmitted", $secretary, 'resubmitted']
                        : ["{$gathering} Attendance submitted", $secretary, 'submitted'],
                };

                return [
                    'id'          => $first->batch_id ?? $first->id,
                    'icon_type'   => $iconType,
                    'title'       => $title,
                    'description' => $description,
                    'time_ago'    => $first->updated_at?->diffForHumans() ?? 'Recently',
                    'status'      => $status,
                ];
            })
            ->take(4) // 2x2 grid = 4 items
            ->values()
            ->toArray();
    }

    public function getCalendarMarks($user, int $monthNumber, int $year): array
    {
        $month = Carbon::create($year, $monthNumber, 1)->startOfDay();
        $monthStart = $month->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $recordDays = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$monthStart, $monthEnd])
            ->distinct()
            ->pluck('day')
            ->map(fn ($date) => Carbon::parse($date)->day)
            ->unique()
            ->values();

        $pendingDays = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->get()
            ->flatMap(function ($draft) use ($monthStart, $monthEnd) {
                return collect($draft->parsed_data ?? [])
                    ->pluck('day')
                    ->filter(fn ($date) => $date >= $monthStart && $date <= $monthEnd)
                    ->map(fn ($date) => Carbon::parse($date)->day);
            })
            ->unique()
            ->values();

        $highlightDays = $recordDays
            ->map(fn ($day) => ['day' => $day, 'type' => 'has_records'])
            ->concat($pendingDays->map(fn ($day) => ['day' => $day, 'type' => 'pending']))
            ->values()
            ->toArray();

        return [
            'month' => $month->format('F Y'),
            'year' => $month->year,
            'days_in_month' => $month->daysInMonth,
            'starts_on' => $month->format('l'),
            'today' => now()->year === $year && now()->month === $monthNumber ? now()->day : null,
            'highlight_days' => $highlightDays,
        ];
    }

    public function resolveDateRange(string $filter): array
    {
        return match ($filter) {
            'today'      => ['start' => now()->startOfDay(), 'end' => now()->endOfDay()],
            'this_week'  => ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()],
            'this_month' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
            'last_week'  => ['start' => now()->subWeek()->startOfWeek(), 'end' => now()->subWeek()->endOfWeek()],
            'all'        => ['start' => Carbon::createFromDate(2000, 1, 1), 'end' => now()->addYears(10)],
            default      => ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()],
        };
    }

    public function formatGatheringName(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanks Giving',
            default => $type ?? 'Unknown',
        };
    }
}
