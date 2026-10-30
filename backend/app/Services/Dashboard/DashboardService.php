<?php

namespace App\Services\Dashboard;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Escalation;
use App\Models\Program;
use App\Models\Student;
use App\Models\Reminder;
use App\Services\Reminders\GatheringService;
use App\Support\GatheringCycle;
use App\Support\AttendanceReportingPeriod;
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
        // All system reporting weeks run Wednesday through Tuesday.
        $weekStart = GatheringCycle::weekStartForDate(now());
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
        $chartRange = match ($chartPeriod) {
            'last_week' => [
                'start' => $weekStart->copy()->subWeek(),
                'end' => $weekStart->copy()->subWeek()->addDays(6)->endOfDay(),
            ],
            default => $weekRange,
        };

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
            ->inReportingRange([
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
            ->inReportingRange([
                $weekRange['start'],
                $weekRange['end'],
            ]);

        // Match the attendance overview: responses saved in an unsubmitted
        // draft should count toward this week's secretary KPIs as well.
        $weekDraftResponses = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([
                $weekRange['start'],
                $weekRange['end'],
            ])
            ->get()
            ->flatMap(fn ($draft) => collect($draft->parsed_data ?? []));

        return [
            'total_students' => Student::query()
                ->active()
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
            ->inReportingRange([$dateRange['start'], $dateRange['end']]);

        $pendingRecordsQuery = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereNull('validation_status')
                    ->orWhere('validation_status', 'unvalidated');
            });

        $submissionGroup = "CASE WHEN batch_id IS NOT NULL AND batch_id <> '' "
            . "THEN CONCAT('batch:', batch_id) "
            . "ELSE CONCAT('legacy:', gathering_type, '|', DATE_FORMAT(day, '%Y-%m-%d'), '|', submitted_by) END";
        $pendingReview = $pendingRecordsQuery
            ->selectRaw("{$submissionGroup} AS submission_group")
            ->groupByRaw($submissionGroup)
            ->get()
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
            ->inReportingRange([$dateRange['start'], $dateRange['end']]);

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
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
            ->groupBy('gathering_type', 'status')
            ->get();

        $draftsByType = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
            ->get()
            ->groupBy('gathering_type');

        $categories = ['pm', 'ws', 'tg'];
        $chartData = [];

        foreach ($categories as $cat) {
            $presentCount = (int) ($records->first(fn ($row) => $row->gathering_type === $cat && $row->status === 'present')->total ?? 0);
            $absentCount = (int) ($records->first(fn ($row) => $row->gathering_type === $cat && $row->status === 'no_response')->total ?? 0);

            // Unsubmitted drafts are part of the secretary's attendance overview too.
            // parsed_data stores the same per-student status values as saved records.
            foreach ($draftsByType->get($cat, collect()) as $draft) {
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
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
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
                    'program'      => $first->program ?? $first->student?->program ?? 'N/A',
                    'year_level'   => $first->year_level ?? $first->student?->year_level ?? 'N/A',
                    'date'         => AttendanceReportingPeriod::day($group)->format('M d, Y'),
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
        $totalManagedStudents = Student::query()->active()->accessibleTo($user)->count();

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
                    'unsubmitted_at' => $draft->unsubmitted_at?->toIso8601String(),
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
            ->map(function ($group) use ($totalManagedStudents, $user) {
                $first = $group->first();
                $dayCarbon = AttendanceReportingPeriod::day($group);

                $presentCount = $group->where('status', 'present')->count();
                $status = $this->aggregateRecordStatus($group);

                return [
                    'id'             => 'record-' . ($first->batch_id ?? ($first->gathering_type . '-' . $dayCarbon->format('Y-m-d'))),
                    'ref_id'         => null,
                    'batch_id'       => $first->batch_id ?? null,
                    'source'         => 'record',
                    'can_unsubmit' => $group->every(fn ($row) => (int) $row->submitted_by === (int) $user->id)
                        && (!$group->contains(fn ($row) => $row->validation_status === 'validated')
                            || \App\Support\AttendanceUnsubmitPolicy::approvedWeekAllowed($dayCarbon)),
                    'submitted_by_id' => $first->submitted_by,
                    'gathering_type' => $first->gathering_type,
                    'gathering'      => $this->formatGatheringName($first->gathering_type),
                    'date'           => $dayCarbon->format('F j, Y'),
                    'day_iso'        => $dayCarbon->toDateString(),
                    'raw_day'        => $dayCarbon,
                    'students'       => "{$presentCount}/{$totalManagedStudents} students",
                    'status'         => $status,
                    'status_label'   => match ($status) {
                        'approved' => 'Approved',
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
        $monthLastDay = $month->copy()->endOfMonth()->startOfDay();
        $totalManagedStudents = Student::query()->active()->accessibleTo($user)->count();

        $responsesByGathering = AttendanceRecord::query()
            ->select('gathering_type', 'student_id', 'day', 'reporting_day')
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->where('status', 'present')
            ->inReportingRange([$monthStart, $monthEnd])
            ->get()
            ->groupBy('gathering_type')
            ->map(fn ($group) => $group->map(fn ($record) => [
                'student_id' => (int) $record->student_id,
                'day' => Carbon::parse($record->reporting_day ?? $record->day)->toDateString(),
            ]));

        $draftResponsesByGathering = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([$monthStart, $monthEnd])
            ->get()
            ->flatMap(fn (AttendanceDraft $draft) => collect($draft->parsed_data ?? [])
                ->filter(fn (array $entry) => ($entry['status'] ?? null) === 'present'
                    && ! empty($entry['student_id']))
                ->map(fn (array $entry) => [
                    'gathering_type' => $entry['gathering_type'] ?? $draft->gathering_type,
                    'student_id' => (int) $entry['student_id'],
                    'day' => Carbon::parse($draft->day)->toDateString(),
                ]))
            ->groupBy('gathering_type')
            ->map(fn ($group) => $group->map(fn ($entry) => [
                'student_id' => $entry['student_id'],
                'day' => $entry['day'],
            ]));

        return collect(['pm', 'ws', 'tg'])
            ->map(function ($type) use ($responsesByGathering, $draftResponsesByGathering, $totalManagedStudents, $month, $monthLastDay) {
                $presentCount = $responsesByGathering->get($type, collect())
                    ->concat($draftResponsesByGathering->get($type, collect()))
                    ->unique(fn ($response) => $response['student_id'] . '|' . $response['day'])
                    ->count();
                $occurrences = app(GatheringService::class)
                    ->countScheduledOccurrences($type, $month, $monthLastDay);
                $expectedResponses = $totalManagedStudents * $occurrences;
                $percentage = $expectedResponses > 0
                    // Percentage = present responses ÷ expected responses × 100
                    ? min(100, (int) round(($presentCount / $expectedResponses) * 100))
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
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
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
                    default => ["{$gathering} Attendance submitted", $secretary, 'submitted'],
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
            'this_week'  => ['start' => GatheringCycle::weekStartForDate(now()), 'end' => GatheringCycle::weekEndForDate(now())],
            'this_month' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
            'last_week'  => ['start' => GatheringCycle::weekStartForDate(now())->subWeek(), 'end' => GatheringCycle::weekEndForDate(now())->subWeek()],
            'all'        => ['start' => Carbon::createFromDate(2000, 1, 1), 'end' => now()->addYears(10)],
            default      => ['start' => GatheringCycle::weekStartForDate(now()), 'end' => GatheringCycle::weekEndForDate(now())],
        };
    }

    /** ============================================================
     * For Chancellor
     *============================================================*/
    public function getChancellorDashboardPayload($user, array $filters): array
    {
        $selectedDate = Carbon::parse($filters['date'] ?? now());
        $dateRange = [
            'start' => GatheringCycle::weekStartForDate($selectedDate),
            'end' => GatheringCycle::weekEndForDate($selectedDate),
        ];
        $reportingWeeks = collect();
        $lastWeekStart = GatheringCycle::weekStartForDate($selectedDate->copy()->endOfMonth());
        for ($start = GatheringCycle::weekStartForDate($selectedDate->copy()->startOfMonth()), $number = 1;
            $start->lessThanOrEqualTo($lastWeekStart);
            $start->addWeek(), $number++) {
            $end = $start->copy()->addDays(6);
            $reportingWeeks->push([
                'number' => $number,
                'label' => 'Week ' . $number,
                'week_start' => $start->toDateString(),
                'week_end' => $end->toDateString(),
                'range_label' => $start->format('M j') . ' – ' . $end->format('M j, Y'),
            ]);
        }
        // Use the program catalog so seeded programs remain available without enrolled students.
         $allPrograms = Program::query()
            ->orderBy('code')
            ->pluck('code');
         $programNames = Program::query()->pluck('name', 'code');
        $selectedProgram = $filters['program'] ?? null;
        $selectedYearLevel = $filters['year_level'] ?? null;
        $programs = $allPrograms;

        if ($selectedProgram !== null) {
            $programs = $programs
                ->filter(fn ($programCode) => $programCode === $selectedProgram)
                ->values();
        }

        // The table and chart share the month's Wednesday-to-Tuesday weeks.
        $reportingWeeksStart = Carbon::parse($reportingWeeks->first()['week_start']);
        $reportingWeeksEnd = Carbon::parse($reportingWeeks->last()['week_end'])->endOfDay();

        $reportingWeekRecords = AttendanceRecord::query()
            ->with('student:id,program,year_level')
            ->inReportingRange([
                $reportingWeeksStart,
                $reportingWeeksEnd,
            ])
            ->get();
        $reportingWeekRecords = $reportingWeekRecords->concat(
            $this->getDraftAttendanceRows($reportingWeeksStart, $reportingWeeksEnd)
        );

        $rosters = Student::query()->active()->whereIn('program', $programs)
            ->get(['id', 'program', 'year_level'])
            ->groupBy(fn ($student) => $student->program . '|' . $student->year_level);
        $responses = app(AttendanceResponseSummary::class);
        $summarizeProgram = function ($programCode, $programRecords, $yearLevel = null, $weekStart = null) use ($rosters, $responses, $dateRange): array {
            // Match the Coordinator's offered year groups and PM/WS/TG slots.
            $years = $yearLevel !== null ? [(int) $yearLevel] : range(1, $programCode === 'ACT' ? 2 : 4);
            $ranges = GatheringCycle::getRangesForWeekStart($weekStart ?? $dateRange['start']);
            $slots = collect($years)->flatMap(function ($year) use ($programCode, $programRecords, $rosters, $responses, $ranges) {
                $ids = $rosters->get($programCode . '|' . $year, collect())->pluck('id')->map(fn ($id) => (int) $id)->all();
                return collect(['pm', 'ws', 'tg'])->map(fn ($type) => $responses->gathering($programRecords, $ids, $type, [
                    'start' => $ranges[$type]['start']->toDateString(), 'end' => $ranges[$type]['end']->toDateString(),
                ]));
            });
            $completed = $slots->where('status', 'completed')->count();
            $hasResponses = $slots->contains(fn ($slot) => $slot['responded_students'] > 0);
            return [
                'status' => $completed === $slots->count() ? 'completed' : ($hasResponses ? 'incomplete' : 'no_report'),
                'percentage' => (int) round($completed / max(1, $slots->count()) * 100),
                'completed_slots' => $completed,
                'total_slots' => $slots->count(),
                'has_responses' => $hasResponses,
                'responded_students' => $slots->sum('responded_students'),
                'expected_responses' => $slots->sum('total_students'),
            ];
        };
        $getProgramStatus = fn ($code, $rows, $year = null, $start = null): string => $summarizeProgram($code, $rows, $year, $start)['status'];
        $summarizeMonth = function ($code, $year = null) use ($reportingWeeks, $reportingWeekRecords, $summarizeProgram): array {
            $weeks = $reportingWeeks->map(fn ($week) => $summarizeProgram(
                $code, $reportingWeekRecords, $year, Carbon::parse($week['week_start'])
            ));
            $completed = $weeks->sum('completed_slots');
            $total = $weeks->sum('total_slots');
            $hasResponses = $weeks->contains(fn ($week) => $week['has_responses']);
            $responded = $weeks->sum('responded_students');
            $expected = $weeks->sum('expected_responses');
            return [
                'status' => $total > 0 && $completed === $total ? 'completed' : ($hasResponses ? 'incomplete' : 'no_report'),
                'percentage' => (int) round($responded / max(1, $expected) * 100),
                'responded_students' => $responded,
                'expected_responses' => $expected,
            ];
        };

        // Data for the Programs Needing Attention table
        // and Program Completion Overview.
        $programReporting = $programs->map(
            function ($programCode) use (
                $reportingWeekRecords,
                $reportingWeeks,
                $getProgramStatus,
                $summarizeMonth,
                $programNames
            ) {
                $weeklyStatuses = $reportingWeeks
                    ->map(function (array $week) use (
                        $programCode,
                        $reportingWeekRecords,
                        $getProgramStatus
                    ) {
                        $weekStart = Carbon::parse($week['week_start']);
                        $weekEnd = Carbon::parse($week['week_end'])->endOfDay();

                        $recordsForWeek = $reportingWeekRecords->filter(
                            fn ($record) => ($record->reporting_day ?? $record->day)->betweenIncluded(
                                $weekStart,
                                $weekEnd
                            )
                        );

                        return $getProgramStatus($programCode, $recordsForWeek, null, $weekStart);
                    })
                    ->values()
                    ->toArray();

                $monthly = $summarizeMonth($programCode);
                return [
                    'code' => $programCode,
                    'name' => $programNames[$programCode] ?? $programCode,
                    'status' => $monthly['status'],
                    'completion' => $monthly['percentage'],
                    'responded_students' => $monthly['responded_students'],
                    'expected_responses' => $monthly['expected_responses'],

                    // Indexed in the same order as reporting_weeks.
                    'weekly_statuses' => $weeklyStatuses,
                ];
            }
        )->values();

        $programYearReporting = $programReporting;

        if ($selectedYearLevel !== null) {
            $yearPrograms = (int) $selectedYearLevel > 2
                ? $programs->reject(fn ($programCode) => $programCode === 'ACT')->values()
                : $programs;
            $reportingWeekYearRecords = $reportingWeekRecords;

            $programYearReporting = $yearPrograms->map(
                function ($programCode) use (
                    $reportingWeekYearRecords,
                    $reportingWeeks,
                    $selectedYearLevel,
                    $getProgramStatus,
                    $summarizeMonth,
                    $programNames
                ) {
                    $weeklyStatuses = $reportingWeeks
                        ->map(function (array $week) use (
                            $programCode,
                            $reportingWeekYearRecords,
                            $selectedYearLevel,
                            $getProgramStatus
                        ) {
                            $weekStart = Carbon::parse($week['week_start']);
                            $weekEnd = Carbon::parse($week['week_end'])->endOfDay();
                            $recordsForWeek = $reportingWeekYearRecords->filter(
                                fn ($record) => ($record->reporting_day ?? $record->day)->betweenIncluded(
                                    $weekStart,
                                    $weekEnd
                                )
                            );

                            return $getProgramStatus($programCode, $recordsForWeek, $selectedYearLevel, $weekStart);
                        })
                        ->values()
                        ->toArray();

                    $monthly = $summarizeMonth($programCode, $selectedYearLevel);
                    return [
                        'code' => $programCode,
                        'name' => $programNames[$programCode] ?? $programCode,
                        'year_level' => (int) $selectedYearLevel,
                        'status' => $monthly['status'],
                        'completion' => $monthly['percentage'],
                        'responded_students' => $monthly['responded_students'],
                        'expected_responses' => $monthly['expected_responses'],
                        'weekly_statuses' => $weeklyStatuses,
                    ];
                }
            )->values();
        }

        // Every Chancellor graph and card follows the selected program year.
        $programReporting = $programYearReporting;
        $scopedPrograms = $programReporting->pluck('code');
        $respondedResponses = $programReporting->sum('responded_students');
        $expectedResponses = $programReporting->sum('expected_responses');

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

        $completionRate = $expectedResponses > 0
            ? (int) round(($respondedResponses / $expectedResponses) * 100)
            : 0;

        // Data for the Progress by Week stacked bar chart.
        $weeklyProgress = $reportingWeeks
            ->map(function (array $week) use (
                $reportingWeekRecords,
                $scopedPrograms,
                $selectedYearLevel,
                $getProgramStatus
            ) {
                $weekStart = Carbon::parse($week['week_start']);
                $weekEnd = Carbon::parse($week['week_end'])->endOfDay();

                $recordsForWeek = $reportingWeekRecords->filter(
                    fn ($record) => ($record->reporting_day ?? $record->day)->betweenIncluded(
                        $weekStart,
                        $weekEnd
                    )
                );

                $statuses = $scopedPrograms->map(
                    fn ($programCode) => $getProgramStatus(
                        $programCode,
                        $recordsForWeek,
                        $selectedYearLevel,
                        $weekStart
                    )
                );

                return [
                    ...$week,
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
            'programs' => $allPrograms->values(),

            // Four cards at the top
            'summary' => [
                'completed' => $completed,
                'incomplete' => $incomplete,
                'no_report' => $noReport,
                'total_programs' => Program::query()->where('is_active', true)->count(),
                'completion_rate' => $completionRate,
                'escalation_count' => Escalation::query()->visibleTo($user)->count(),
            ],

            // Overall Reporting Progress card
            'overall_progress' => [
                'percentage' => $completionRate,
                'completed_programs' => $completed,
                'total_programs' => $totalPrograms,
                'responded_students' => $respondedResponses,
                'expected_responses' => $expectedResponses,
                'tally' => "{$respondedResponses} of {$expectedResponses} expected responses recorded",
            ],

            // Programs Needing Attention table
            // plus the Program Completion Overview list
            'program_reporting' => $programReporting,
            'program_year_reporting' => $programYearReporting,

            // Progress by Week chart
            'weekly_progress' => $weeklyProgress,
            'reporting_weeks' => $reportingWeeks,
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

    /**
     * Convert unsubmitted draft rows to the small record shape used by the
     * Chancellor's reporting charts. Drafts are removed when submitted, so
     * these rows do not overlap with persisted attendance records.
     */
    private function getDraftAttendanceRows($start, $end): \Illuminate\Support\Collection
    {
        return AttendanceDraft::query()
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([$start, $end])
            ->get()
            ->flatMap(function (AttendanceDraft $draft) {
                return collect($draft->parsed_data ?? [])
                    ->filter(fn (array $entry) => ! empty($entry['student_id']))
                    ->map(function (array $entry) use ($draft) {
                        return (object) [
                            'student_id' => (int) $entry['student_id'],
                            'student' => (object) [
                                'program' => $entry['program'] ?? null,
                                'year_level' => $entry['year_level'] ?? null,
                            ],
                            'gathering_type' => $entry['gathering_type'] ?? $draft->gathering_type,
                            'status' => $entry['status'] ?? 'no_response',
                            'day' => Carbon::parse($draft->day),
                        ];
                    });
            })
            ->values();
    }
}
