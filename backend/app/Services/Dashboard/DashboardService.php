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
        $data = new SecretaryDashboardData($user, [
            $weekRange, $chartRange,
            ['start' => $month->copy()->startOfMonth(), 'end' => $month->copy()->endOfMonth()],
        ]);

        return [
            'role' => 'secretary',

            // Blue hero card: “This month's Attendance / No response”
            'monthly_summary' => $this->getSecretaryMonthlySummary(
                $user,
                $month,
                $data
            ),

            // Four cards under the hero card
            'kpis' => $this->getSecretaryKpis(
                $user,
                $weekRange,
                $data
            ),

            // Attendance Overview bar chart
            'attendance_overview' => $this->getAttendanceChartData(
                $user,
                $chartPeriod,
                $chartRange,
                $data
            )['series'],

            // Recent Attendance Record table
            'recent_records' => $this->getRecentRecords(
                $user,
                $weekRange,
                $data
            ),

            // Task and Reminders card
            'tasks_and_reminders' => $this->getSecretaryTasksAndReminders(
                $user
            ),

            // Monthly Attendance by Gathering card
            'monthly_gathering' => $this->getMonthlyGatheringData(
                $user,
                $month->month,
                $month->year,
                $data
            ),
        ];
    }

    private function getSecretaryMonthlySummary($user, Carbon $month, ?SecretaryDashboardData $data = null): array
    {
        $monthRange = [
            'start' => $month->copy()->startOfMonth(),
            'end' => $month->copy()->endOfMonth(),
        ];

        $records = AttendanceRecord::query()
            ->whereHas(
                'student',
                fn ($query) => $query->accessibleTo($user)
            )
            ->inReportingRange([
                $monthRange['start'],
                $monthRange['end'],
            ]);

        $monthDrafts = $data?->draftsIn($monthRange) ?? AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([
                $monthRange['start'],
                $monthRange['end'],
            ])
            ->get();
        $monthDraftResponses = $monthDrafts->flatMap(fn ($draft) => $data?->entriesFor($draft) ?? collect($draft->parsed_data ?? []));

        $counts = $data?->statusCounts($monthRange)
            ?? $records->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        return [
            'attendance' => (int) ($counts['present'] ?? 0) + $monthDraftResponses->where('status', 'present')->count(),
            'no_response' => (int) ($counts['no_response'] ?? 0) + $monthDraftResponses->where('status', 'no_response')->count(),

            'month_label' => $month->format('F Y'),
        ];
    }

    private function getSecretaryKpis($user, array $weekRange, ?SecretaryDashboardData $data = null): array
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
        $weekDrafts = $data?->draftsIn($weekRange) ?? AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([
                $weekRange['start'],
                $weekRange['end'],
            ])
            ->get();
        $weekDraftResponses = $weekDrafts->flatMap(fn ($draft) => $data?->entriesFor($draft) ?? collect($draft->parsed_data ?? []));
        $counts = $data?->statusCounts($weekRange)
            ?? $weekRecords->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        return [
            'total_students' => $data?->studentCount ?? Student::query()
                ->active()
                ->accessibleTo($user)
                ->count(),

            'week_respondents' => (int) ($counts['present'] ?? 0) + $weekDraftResponses->where('status', 'present')->count(),

            'week_no_response' => (int) ($counts['no_response'] ?? 0) + $weekDraftResponses->where('status', 'no_response')->count(),

            'pending_submission' => $data?->drafts->count() ?? AttendanceDraft::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['draft', 'pending'])
                ->count(),
            'escalation_count' => Escalation::query()->visibleTo($user)->count(),
        ];
    }

    private function getSecretaryTasksAndReminders($user): array
    {
        $reminders = Reminder::query()
            ->relevantToCurrentRoster()
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
            ->values();

        $escalations = Escalation::query()
            ->visibleTo($user)
            ->with('student')
            ->mostRecentFirst()
            ->limit(3)
            ->get()
            ->map(function (Escalation $escalation) {
                $student = $escalation->student;

                return [
                    'id' => $escalation->id,
                    'type' => 'escalation',
                    'category' => 'Escalation',
                    'title' => $student->name,
                    'description' => "{$student->name} requires an Escalation Level 1 follow up.",
                    'date' => $escalation->updated_at?->toIso8601String(),
                    'status' => 'escalation',
                    'status_label' => 'Escalation Report',
                ];
            });

        return $escalations->concat($reminders)->values()->toArray();
    }
    /** ============================================================
     * For Program Heads
     *============================================================*/
    public function getProgramHeadDashboardPayload($user, array $filters): array
    {
        $dateRange = $this->resolveDateRange($filters['week'] ?? 'all');
        $records = AttendanceRecord::query()
            ->with(['student:id,name,program,year_level', 'submitter:id,name', 'validator:id,name'])
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
            ->get();

        return [
            'summary'        => $this->getProgramHeadSummary($user, $dateRange, $records),
            'pending_list'   => $this->getProgramHeadPendingList($user, $dateRange, $records),
            'recent_records' => $this->getProgramHeadRecentActivity($user, $dateRange, $records),
            'role'           => 'program_head',
        ];
    }

    public function getProgramHeadSummary($user, array $dateRange, ?\Illuminate\Support\Collection $records = null): array
    {
        if ($records === null) {
            $records = AttendanceRecord::query()
                ->whereHas('student', fn ($query) => $query->accessibleTo($user))
                ->inReportingRange([$dateRange['start'], $dateRange['end']])
                ->get(['batch_id', 'gathering_type', 'day', 'reporting_day', 'submitted_by', 'validation_status']);
        }

        // Evaluate the whole submission before counting it as approved. Filtering
        // approved student rows first would count partially approved batches too.
        $submissions = $records
            ->groupBy(function ($record) {
                if (!empty($record->batch_id)) {
                    return 'batch:' . $record->batch_id;
                }

                $day = Carbon::parse($record->reporting_day ?? $record->day)->toDateString();
                return 'legacy:' . $record->gathering_type . '|' . $day . '|' . $record->submitted_by;
            });

        $approved = $submissions->filter(fn ($batch) => $batch->every(
            fn ($record) => in_array($record->validation_status, ['validated', 'approved'], true)
        ))->count();
        $total = $submissions->count();
        $pendingReview = $total - $approved;

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

    public function getAttendanceChartData($user, string $filter, array $dateRange, ?SecretaryDashboardData $data = null): array
    {
        $records = $data ? $data->recordsIn($dateRange)
            ->groupBy(fn ($row) => $row->gathering_type.'|'.$row->status)
            ->map(fn ($rows) => (object) ['gathering_type' => $rows->first()->gathering_type,
                'status' => $rows->first()->status, 'total' => $rows->count()])
            ->values() : AttendanceRecord::query()
            ->select('gathering_type', 'status', DB::raw('COUNT(*) as total'))
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
            ->groupBy('gathering_type', 'status')
            ->get();

        $draftsByType = ($data?->draftsIn($dateRange) ?? AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
            ->get())
            ->groupBy('gathering_type');

        $categories = ['pm', 'ws', 'tg'];
        $chartData = [];

        foreach ($categories as $cat) {
            $presentCount = (int) ($records->first(fn ($row) => $row->gathering_type === $cat && $row->status === 'present')->total ?? 0);
            $absentCount = (int) ($records->first(fn ($row) => $row->gathering_type === $cat && $row->status === 'no_response')->total ?? 0);

            // Unsubmitted drafts are part of the secretary's attendance overview too.
            // parsed_data stores the same per-student status values as saved records.
            foreach ($draftsByType->get($cat, collect()) as $draft) {
                $entries = $data?->entriesFor($draft) ?? collect($draft->parsed_data ?? []);
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

    public function getProgramHeadPendingList($user, array $dateRange, ?\Illuminate\Support\Collection $sharedRecords = null): array
    {
        $records = $sharedRecords ? $sharedRecords
            ->filter(fn ($row) => $row->validation_status === null || $row->validation_status === 'unvalidated')
            ->sortByDesc('day') : AttendanceRecord::query()
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

    public function getRecentRecords($user, ?array $dateRange = null, ?SecretaryDashboardData $data = null): array
    {
        $totalManagedStudents = $data?->studentCount ?? Student::query()->active()->accessibleTo($user)->count();

        // 1. Unsubmitted Drafts
        $drafts = ($data?->drafts->where('status', 'draft') ?? AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->orderBy('updated_at', 'desc')
            ->get())
            ->map(function ($draft) use ($totalManagedStudents, $data) {
                $parsed = $data ? $data->entriesFor($draft)->all() : (array) ($draft->parsed_data ?? []);
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
        $records = app(SecretaryRecentAttendanceQuery::class)->records($user)
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

    public function getMonthlyGatheringData($user, int $monthNumber, int $year, ?SecretaryDashboardData $data = null): array
    {
        $month = Carbon::create($year, $monthNumber, 1)->startOfDay();
        $monthStart = $month->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();
        $monthLastDay = $month->copy()->endOfMonth()->startOfDay();
        $totalManagedStudents = $data?->studentCount ?? Student::query()->active()->accessibleTo($user)->count();

        $responsesByGathering = ($data?->recordsIn(['start' => $monthStart, 'end' => $monthEnd])->where('status', 'present') ?? AttendanceRecord::query()
            ->select('gathering_type', 'student_id', 'day', 'reporting_day')
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->where('status', 'present')
            ->inReportingRange([$monthStart, $monthEnd])
            ->get())
            ->groupBy('gathering_type')
            ->map(fn ($group) => $group->map(fn ($record) => [
                'student_id' => (int) $record->student_id,
                'day' => Carbon::parse($record->reporting_day ?? $record->day)->toDateString(),
            ]));

        $draftResponsesByGathering = ($data?->draftsIn(['start' => $monthStart, 'end' => $monthEnd]) ?? AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([$monthStart, $monthEnd])
            ->get())
            ->flatMap(fn (AttendanceDraft $draft) => ($data?->entriesFor($draft) ?? collect($draft->parsed_data ?? []))
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

    public function getProgramHeadRecentActivity($user, array $dateRange, ?\Illuminate\Support\Collection $sharedRecords = null): array
    {
        $records = $sharedRecords?->sortByDesc('updated_at') ?? AttendanceRecord::query()
            ->with(['student', 'submitter', 'validator'])
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->inReportingRange([$dateRange['start'], $dateRange['end']])
            ->orderBy('updated_at', 'desc')
            ->get();

        $activities = $records
            ->groupBy(fn ($r) => $r->batch_id ?? ($r->gathering_type . '|' . $r->day . '|' . $r->submitted_by))
            ->sortByDesc(fn ($group) => $group->first()->updated_at)
            ->map(function ($group) use ($user) {
                $first = $group->first();
                $status = $first->validation_status ?? 'unvalidated';
                $gathering = $this->formatGatheringName($first->gathering_type);
                $secretary = $first->submitter?->name ?? 'Unknown';
                $approvalDescription = $first->validated_by !== null && (int) $first->validated_by === (int) $user->id
                    ? 'You approved the record'
                    : ($first->validator?->name
                        ? "{$first->validator->name} approved the record"
                        : 'The record was approved');

                [$title, $description, $iconType] = match ($status) {
                    'validated' => [
                        "{$gathering} attendance submitted",
                        $approvalDescription,
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
                    'activity_at' => $first->updated_at?->toIso8601String(),
                ];
            });

        if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
            $programCodes = $user->assignedProgramCodes();
            $withdrawals = \App\Models\AuditLog::query()
                ->where('action', 'Unsubmitted attendance submission')
                ->where('target_table', 'attendance_records')
                ->where('result', 'success')
                ->with('actor:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lazy(100)
                ->flatMap(function ($log) use ($programCodes, $dateRange) {
                    $details = json_decode($log->details ?? '', true);
                    return collect($details['submissions'] ?? [])
                        ->filter(fn ($entry) => in_array($entry['program'] ?? null, $programCodes, true)
                            && ($entry['reporting_day'] ?? '') >= $dateRange['start']
                            && ($entry['reporting_day'] ?? '') <= $dateRange['end'])
                        ->map(fn ($entry, $index) => [
                            'id' => 'withdrawal-'.$log->id.'-'.$index,
                            'icon_type' => 'withdrawn',
                            'title' => $this->formatGatheringName($entry['gathering_type']).' submission withdrawn',
                            'description' => ($log->actor?->name ?? 'Secretary').' withdrew the submission',
                            'time_ago' => $log->created_at?->diffForHumans() ?? 'Recently',
                            'status' => 'withdrawn',
                            'activity_at' => $log->created_at?->toIso8601String(),
                        ]);
                })->take(4);
            $activities = $activities->concat($withdrawals);
        }

        return $activities->sortByDesc('activity_at')
            ->take(4) // 2x2 grid = 4 items
            ->map(fn ($activity) => \Illuminate\Support\Arr::except($activity, 'activity_at'))
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
        for ($start = GatheringCycle::firstWeekStartForMonth($selectedDate), $number = 1;
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
         $allPrograms = app(ReportingRoster::class)->programsForMonth($selectedDate->copy()->startOfMonth())->pluck('code');
        $programCatalog = Program::query()->get()->keyBy('code');
        $programNames = $programCatalog->pluck('name', 'code');
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

        // Match the roster the response calculations already use, rather than
        // fetching attendance for every program and year before filtering it.
        $rosters = Student::query()->whereIn('program', $programs)
            ->when($selectedYearLevel !== null, fn ($query) => $query->where('year_level', $selectedYearLevel))
            ->get(['id', 'program', 'year_level', 'is_active', 'roster_history'])
            ->groupBy(fn ($student) => $student->program . '|' . $student->year_level);
        $rosterIds = $rosters->flatten(1)->pluck('id')->all();

        $reportingWeekRecords = AttendanceRecord::query()
            ->whereIn('student_id', $rosterIds)
            ->inReportingRange([
                $reportingWeeksStart,
                $reportingWeeksEnd,
            ])
            ->get(['student_id', 'gathering_type', 'status', 'day', 'reporting_day']);
        $reportingWeekRecords = $reportingWeekRecords->concat(
            $this->getDraftAttendanceRows($reportingWeeksStart, $reportingWeeksEnd, $rosterIds)
        );

        $reportingRoster = app(ReportingRoster::class);
        $responses = app(AttendanceResponseSummary::class);
        $weeklySummaries = [];
        $summarizeProgram = function ($programCode, $programRecords, $yearLevel = null, $weekStart = null) use ($rosters, $responses, $dateRange, $programCatalog, $reportingRoster, &$weeklySummaries): array {
            $key = $programCode.'|'.($yearLevel ?? 'all').'|'.($weekStart ?? $dateRange['start'])->toDateString();
            if (isset($weeklySummaries[$key])) return $weeklySummaries[$key];
            // Match the Coordinator's offered year groups and PM/WS/TG slots.
            $years = $yearLevel !== null ? [(int) $yearLevel] : range(1, $programCode === 'ACT' ? 2 : 4);
            $ranges = GatheringCycle::getRangesForWeekStart($weekStart ?? $dateRange['start']);
            $slots = collect($years)->flatMap(function ($year) use ($programCode, $programRecords, $rosters, $responses, $ranges, $programCatalog, $reportingRoster) {
                $students = $rosters->get($programCode . '|' . $year, collect());
                return collect(['pm', 'ws', 'tg'])->map(function ($type) use ($students, $programRecords, $responses, $ranges, $programCatalog, $programCode, $reportingRoster) {
                    $range = ['start' => $ranges[$type]['start']->toDateString(), 'end' => $ranges[$type]['end']->toDateString()];
                    $rows = $programRecords->where('gathering_type', $type)->filter(fn ($record) =>
                        ($record->reporting_day ?? $record->day)->toDateString() >= $range['start']
                        && ($record->reporting_day ?? $record->day)->toDateString() <= $range['end']);
                    $ids = $reportingRoster->studentIds($students, $rows, $range['start'], $programCatalog->get($programCode));
                    return $responses->gathering($programRecords, $ids, $type, $range);
                });
            });
            $completed = $slots->where('status', 'completed')->count();
            $hasResponses = $slots->contains(fn ($slot) => $slot['responded_students'] > 0);
            $hasAttendance = $slots->contains(fn ($slot) => $slot['has_attendance']);
            return $weeklySummaries[$key] = [
                'status' => $completed === $slots->count() ? 'completed' : ($hasResponses ? 'incomplete' : 'no_report'),
                'percentage' => (int) round($completed / max(1, $slots->count()) * 100),
                'completed_slots' => $completed,
                'total_slots' => $slots->count(),
                'has_responses' => $hasResponses,
                'has_attendance' => $hasAttendance,
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
            $hasAttendance = $weeks->contains(fn ($week) => $week['has_attendance']);
            $responded = $weeks->sum('responded_students');
            $expected = $weeks->sum('expected_responses');
            return [
                'status' => $total > 0 && $completed === $total ? 'completed' : ($hasResponses ? 'incomplete' : 'no_report'),
                'has_attendance' => $hasAttendance,
                'percentage' => (int) round($responded / max(1, $expected) * 100),
                'responded_students' => $responded,
                'expected_responses' => $expected,
            ];
        };

        // Data for the Programs Needing Attention table
        // and Program Completion Overview.
        $programReporting = $selectedYearLevel !== null ? collect() : $programs->map(
            function ($programCode) use (
                $reportingWeekRecords,
                $reportingWeeks,
                $summarizeProgram,
                $summarizeMonth,
                $programNames
            ) {
                $weeklySummaries = $reportingWeeks
                    ->map(function (array $week) use (
                        $programCode,
                        $reportingWeekRecords,
                        $summarizeProgram
                    ) {
                        $weekStart = Carbon::parse($week['week_start']);
                        $weekEnd = Carbon::parse($week['week_end'])->endOfDay();

                        $recordsForWeek = $reportingWeekRecords->filter(
                            fn ($record) => ($record->reporting_day ?? $record->day)->betweenIncluded(
                                $weekStart,
                                $weekEnd
                            )
                        );

                        return $summarizeProgram($programCode, $recordsForWeek, null, $weekStart);
                    })
                    ->values();

                $monthly = $summarizeMonth($programCode);
                return [
                    'code' => $programCode,
                    'name' => $programNames[$programCode] ?? $programCode,
                    'status' => $monthly['status'],
                    'has_attendance' => $monthly['has_attendance'],
                    'completion' => $monthly['percentage'],
                    'responded_students' => $monthly['responded_students'],
                    'expected_responses' => $monthly['expected_responses'],

                    // Indexed in the same order as reporting_weeks.
                    'weekly_statuses' => $weeklySummaries->pluck('status')->all(),
                    'weekly_has_attendance' => $weeklySummaries->pluck('has_attendance')->all(),
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
                    $summarizeProgram,
                    $summarizeMonth,
                    $programNames
                ) {
                    $weeklySummaries = $reportingWeeks
                        ->map(function (array $week) use (
                            $programCode,
                            $reportingWeekYearRecords,
                            $selectedYearLevel,
                            $summarizeProgram
                        ) {
                            $weekStart = Carbon::parse($week['week_start']);
                            $weekEnd = Carbon::parse($week['week_end'])->endOfDay();
                            $recordsForWeek = $reportingWeekYearRecords->filter(
                                fn ($record) => ($record->reporting_day ?? $record->day)->betweenIncluded(
                                    $weekStart,
                                    $weekEnd
                                )
                            );

                            return $summarizeProgram($programCode, $recordsForWeek, $selectedYearLevel, $weekStart);
                        })
                        ->values();

                    $monthly = $summarizeMonth($programCode, $selectedYearLevel);
                    return [
                        'code' => $programCode,
                        'name' => $programNames[$programCode] ?? $programCode,
                        'year_level' => (int) $selectedYearLevel,
                        'status' => $monthly['status'],
                        'has_attendance' => $monthly['has_attendance'],
                        'completion' => $monthly['percentage'],
                        'responded_students' => $monthly['responded_students'],
                        'expected_responses' => $monthly['expected_responses'],
                        'weekly_statuses' => $weeklySummaries->pluck('status')->all(),
                        'weekly_has_attendance' => $weeklySummaries->pluck('has_attendance')->all(),
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
                // These two cards count program-week icons. Monthly program
                // statuses and response percentages remain separate.
                'completed' => $weeklyProgress->sum('completed'),
                'incomplete' => $weeklyProgress->sum('incomplete'),
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
    private function getDraftAttendanceRows($start, $end, ?array $studentIds = null): \Illuminate\Support\Collection
    {
        return AttendanceDraft::query()
            ->whereIn('status', ['draft', 'pending'])
            ->inReportingRange([$start, $end])
            ->get(['gathering_type', 'day', 'parsed_data'])
            ->flatMap(function (AttendanceDraft $draft) use ($studentIds) {
                return collect($draft->parsed_data ?? [])
                    ->filter(fn (array $entry) => ! empty($entry['student_id'])
                        && ($studentIds === null || in_array((int) $entry['student_id'], $studentIds, true)))
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

