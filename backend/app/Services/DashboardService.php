<?php

namespace App\Services;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Escalation;
use App\Models\Student;
use App\Models\Reminder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{

    /** ============================================================
     * For Secretaries
     *============================================================*/
    public function getGeneralDashboardPayload($user, array $filters): array
    {
        // Secretary dashboard is always centered on the current reporting week.
        // Secretary reporting weeks run Monday through Sunday.
        $weekStart = now()->startOfWeek(Carbon::MONDAY);
        $weekRange = [
            'start' => $weekStart,
            'end' => $weekStart->copy()->addDays(6)->endOfDay(),
        ];

        $month = Carbon::create(
            $filters['year'] ?? now()->year,
            $filters['month'] ?? now()->month,
            1
        );

        $chartPeriod = $filters['attendance_period'] ?? 'week';
        
        $chartRange = $weekRange;

        if ($chartPeriod === 'last_week') {
            $lastWeekStart = $weekStart->copy()->subWeek();
            $chartRange = [
                'start' => $lastWeekStart,
                'end' => $lastWeekStart->copy()->addDays(6)->endOfDay(),
            ];
        }


        $chartRange = $chartPeriod === 'month'
            ? [
                'start' => $month->copy()->startOfMonth(),
                'end' => $month->copy()->endOfMonth()->endOfDay(),
            ]
            : $weekRange;

        return [
            'role' => 'secretary',

            // Blue hero card: “This month's Attendance / No response”
            'monthly_summary' => $this->getSecretaryMonthlySummary(
                $user,
                $month
            ),

            // Four cards under the hero card
            'kpis' => $this->getSecretaryKpis(
                $user,
                $weekRange
            ),

            // Attendance Overview bar chart
            'attendance_overview' => $this->getAttendanceChartData(
                $user,
                $chartPeriod,
                $chartRange
            )['series'],

            // Recent Attendance Record table
            'recent_records' => $this->getRecentRecords(
                $user,
                $weekRange
            ),

            // Task and Reminders card
            'tasks_and_reminders' => $this->getSecretaryTasksAndReminders(
                $user
            ),

            // Monthly Attendance by Gathering card
            'monthly_gathering' => $this->getMonthlyGatheringData(
                $user,
                $month->month,
                $month->year
            ),
        ];
    }

        private function getSecretaryMonthlySummary($user, Carbon $month): array
    {
        $records = AttendanceRecord::query()
            ->whereHas(
                'student',
                fn ($query) => $query->accessibleTo($user)
            )
            ->whereBetween('day', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ]);

        return [
            'attendance' => (clone $records)
                ->where('status', 'present')
                ->count(),

            'no_response' => (clone $records)
                ->where('status', 'no_response')
                ->count(),

            'month_label' => $month->format('F Y'),
        ];
    }

    private function getSecretaryKpis($user, array $weekRange): array
    {
        $weekRecords = AttendanceRecord::query()
            ->whereHas(
                'student',
                fn ($query) => $query->accessibleTo($user)
            )
            ->whereBetween('day', [
                $weekRange['start'],
                $weekRange['end'],
            ]);

        // Match the attendance overview: responses saved in an unsubmitted
        // draft should count toward this week's secretary KPIs as well.
        $weekDraftResponses = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->whereBetween('day', [
                $weekRange['start'],
                $weekRange['end'],
            ])
            ->get()
            ->flatMap(fn ($draft) => collect($draft->parsed_data ?? []));

        return [
            'total_students' => Student::query()
                ->accessibleTo($user)
                ->count(),

            'week_respondents' => (clone $weekRecords)
                ->where('status', 'present')
                ->count() + $weekDraftResponses->where('status', 'present')->count(),

            'week_no_response' => (clone $weekRecords)
                ->where('status', 'no_response')
                ->count() + $weekDraftResponses->where('status', 'no_response')->count(),

            'pending_submission' => AttendanceDraft::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['draft', 'pending'])
                ->count(),
            'escalation_count' => Escalation::query()->visibleTo($user)->count(),
        ];
    }

    private function getSecretaryTasksAndReminders($user): array
    {
        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['upcoming', 'needs_action'])
            ->orderBy('due_date')
            ->limit(4)
            ->get()
            ->map(function ($reminder) {
                return [
                    'id' => $reminder->id,

                    'type' => $reminder->reminder_type ?? 'custom',

                    'category' => $reminder->status === 'needs_action'
                        ? 'Needs Action'
                        : match ($reminder->reminder_type) {
                            'submission_countdown' => 'System Reminder',
                            'gathering' => 'Submission',
                            default => 'My Reminder',
                        },

                    'title' => $reminder->title,

                    'description' => $reminder->description,

                    'date' => optional($reminder->due_date)
                        ->format('F j, Y'),

                    'status' => $reminder->status,

                    'status_label' => match ($reminder->status) {
                        'needs_action' => 'Needs Action',
                        default => 'Upcoming',
                    },
                ];
            })
            ->values()
            ->toArray();
    }
    /** ============================================================
     * For Program Heads
     *============================================================*/
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

        // Attendance records store the approved state as `validated`.
        $approved = (clone $baseQuery)->where('validation_status', 'validated')->count();
        $total = (clone $baseQuery)->count();

        return [
            'pending_review' => $pendingReview,
            'approved' => $approved,
            'escalation_count' => Escalation::query()->visibleTo($user)->count(),
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
            $presentCount = (int) ($records->first(fn ($row) => $row->gathering_type === $cat && $row->status === 'present')->total ?? 0);
            $absentCount = (int) ($records->first(fn ($row) => $row->gathering_type === $cat && $row->status === 'no_response')->total ?? 0);

            // Unsubmitted drafts are part of the secretary's attendance overview too.
            // parsed_data stores the same per-student status values as saved records.
            $drafts = AttendanceDraft::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['draft', 'pending'])
                ->where('gathering_type', $cat)
                ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
                ->get();

            foreach ($drafts as $draft) {
                $entries = collect($draft->parsed_data ?? []);
                $presentCount += $entries->where('status', 'present')->count();
                $absentCount += $entries->where('status', 'no_response')->count();
            }

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

    public function getRecentRecords($user, ?array $dateRange = null): array
    {
        $totalManagedStudents = Student::query()->accessibleTo($user)->count();

        // 1. Unsubmitted Drafts
        $drafts = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($draft) use ($totalManagedStudents) {
                $parsed = is_array($draft->parsed_data) ? $draft->parsed_data : (array) $draft->parsed_data;
                $presentCount = $draft->matched_count ?? collect($parsed)->where('status', 'present')->count();
                $dayCarbon = $draft->day ? Carbon::parse($draft->day) : Carbon::parse($draft->updated_at);

                return [
                    'id'             => 'draft-' . $draft->id,
                    'ref_id'         => $draft->id,
                    'batch_id'       => null,
                    'source'         => 'draft',
                    'gathering_type' => $draft->gathering_type,
                    'gathering'      => $this->formatGatheringName($draft->gathering_type),
                    'date'           => $dayCarbon->format('F j, Y'),
                    'day_iso'        => $dayCarbon->toDateString(),
                    'raw_day'        => $dayCarbon,
                    'students'       => "{$presentCount}/{$totalManagedStudents} students",
                    'status'         => 'validating',
                    'status_label'   => 'Validating',
                    'action'         => 'Review',
                ];
            });

        // 2. Submitted Attendance Records grouped by batch_id or gathering_type|day
        $recordsQuery = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user));

        $records = $recordsQuery->get()
            ->groupBy(function ($r) {
                if (!empty($r->batch_id)) {
                    return $r->batch_id;
                }
                $dayFormatted = $r->day instanceof Carbon
                    ? $r->day->format('Y-m-d')
                    : Carbon::parse($r->day)->format('Y-m-d');
                return $r->gathering_type . '|' . $dayFormatted;
            })
            ->map(function ($group) use ($totalManagedStudents) {
                $first = $group->first();
                $dayCarbon = $first->day instanceof Carbon
                    ? $first->day
                    : Carbon::parse($first->day);

                $presentCount = $group->where('status', 'present')->count();
                $status = $this->aggregateRecordStatus($group);

                return [
                    'id'             => 'record-' . ($first->batch_id ?? ($first->gathering_type . '-' . $dayCarbon->format('Y-m-d'))),
                    'ref_id'         => null,
                    'batch_id'       => $first->batch_id ?? null,
                    'source'         => 'record',
                    'gathering_type' => $first->gathering_type,
                    'gathering'      => $this->formatGatheringName($first->gathering_type),
                    'date'           => $dayCarbon->format('F j, Y'),
                    'day_iso'        => $dayCarbon->toDateString(),
                    'raw_day'        => $dayCarbon,
                    'students'       => "{$presentCount}/{$totalManagedStudents} students",
                    'status'         => $status,
                    'status_label'   => match ($status) {
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default    => 'Pending',
                    },
                    'action'         => 'View',
                ];
            })
            ->values();

        // 3. Merge drafts and submitted records, sort by raw_day desc, take top 5
        return $drafts->concat($records)
            ->sortByDesc(fn ($row) => $row['raw_day'])
            ->take(5)
            ->map(function ($row) {
                unset($row['raw_day']);
                return $row;
            })
            ->values()
            ->toArray();
    }

    private function aggregateRecordStatus($group): string
    {
        if ($group->contains('validation_status', 'rejected')) {
            return 'rejected';
        }
        if ($group->every(fn ($r) => in_array($r->validation_status, ['validated', 'approved']))) {
            return 'approved';
        }
        return 'pending';
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

    /** ============================================================
     * For CM Coordinator
     *============================================================*/
    public function getCmCoordinatorDashboardPayload($user, array $filters): array
    {
        // CM Coordinator dashboard
    }

    /** ============================================================
     * For Chancellor
     *============================================================*/
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
            'role' => $user->role,

            // Four cards at the top
            'summary' => [
                'completed' => $completed,
                'incomplete' => $incomplete,
                'no_report' => $noReport,
                'total_programs' => $totalPrograms,
                'completion_rate' => $completionRate,
                'escalation_count' => Escalation::query()->visibleTo($user)->count(),
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

    public function formatGatheringName(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanksgiving',
            default => $type ?? 'Unknown',
        };
    }
}
