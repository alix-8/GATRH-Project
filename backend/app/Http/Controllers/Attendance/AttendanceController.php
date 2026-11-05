<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\AttendanceRecord;
use App\Models\AttendanceDraft;
use App\Models\Reminder;
use App\Models\Student;
use App\Services\Attendance\AttendanceParsingService;
use App\Services\Audit\AuditLogService;
use App\Support\GatheringCycle;
use App\Support\AttendanceReportingPeriod;
use App\Support\AttendanceUnsubmitPolicy;

class AttendanceController extends Controller
{
    protected AttendanceParsingService $parsingService;
    protected AuditLogService $auditLogService;

    public function __construct(
        AttendanceParsingService $parsingService,
        AuditLogService $auditLogService
    ) {
        $this->parsingService = $parsingService;
        $this->auditLogService = $auditLogService;
    }

    private function importPeriodCycle(?string $period): ?array
    {
        if (!in_array($period, ['last', 'current'], true)) return null;
        return GatheringCycle::getImportPeriodRanges($period);
    }

    /** Preflight both import choices so the selector can show existing records and exact ranges. */
    public function importPeriods(Request $request)
    {
        $user = $request->user();
        $periods = [];
        foreach (['last', 'current'] as $period) {
            $ranges = GatheringCycle::getImportPeriodRanges($period);
            $counts = $this->countImportEntries($user, $ranges);
            $gatherings = [];
            foreach ($ranges as $type => $range) {
                $start = $range['start']->toDateString();
                $end = $range['end']->toDateString();
                $recordCount = (int) ($counts['records'][$type] ?? 0);
                $draftCount = (int) ($counts['drafts'][$type] ?? 0);
                $protectedCount = (int) ($counts['protected'][$type] ?? 0);
                $hasProtected = $protectedCount > 0;
                $gatherings[$type] = [
                    'start' => $start,
                    'end' => $end,
                    'label' => GatheringCycle::formatRangeLabel($range['start'], $range['end']),
                    'is_upcoming' => $range['start']->greaterThan(Carbon::today()),
                    'count' => $recordCount + $draftCount,
                    'confirmable_count' => $hasProtected ? 0 : $recordCount + $draftCount,
                    'protected' => $hasProtected,
                    'submitted' => $recordCount > 0,
                ];
            }
            $periods[$period] = $gatherings;
        }
        return response()->json(['periods' => $periods]);
    }

    /**
     * Check for same-week attendance before the import page displays its student preview.
     */
    public function checkDuplicates(Request $request)
    {
        $validated = $request->validate([
            'raw_text'      => 'required|string',
            'gathering_type'=> 'required|in:pm,ws,tg,week',
            'cycle'         => 'nullable',
            'week_range'    => 'nullable',
        ]);

        $user = $request->user();
        $cycle = $this->importPeriodCycle($request->input('week_period')) ?? $request->input('cycle') ?? $request->input('week_range');
        $types = $validated['gathering_type'] === 'week'
            ? ['pm', 'ws', 'tg']
            : [$validated['gathering_type']];
        $ranges = [];
        foreach ($types as $type) {
            $ranges[$type] = $this->parsingService->getApplicableWeekRange($type, $cycle, $validated['raw_text']);
        }
        $counts = $this->countImportEntries($user, $ranges);
        $duplicates = [];

        foreach ($types as $type) {
            $range = $ranges[$type];
            $start = $range['start']->toDateString();
            $end = $range['end']->toDateString();

            $recordCount = (int) ($counts['records'][$type] ?? 0);
            $protectedRecordCount = (int) ($counts['protected'][$type] ?? 0);

            if ($protectedRecordCount > 0) {
                return $this->protectedAttendanceResponse($type, $validated['gathering_type'] === 'week');
            }

            $draftCount = (int) ($counts['drafts'][$type] ?? 0);

            $count = $recordCount + $draftCount;
            if ($count > 0) {
                $duplicates[] = [
                    'gathering_type'   => $type,
                    'duplicates_count' => $count,
                    'week_range'       => [
                        'start' => $start,
                        'end'   => $end,
                        'label' => $range['start']->format('F j') . ' - ' . $range['end']->format('F j, Y'),
                    ],
                ];
            }
        }

        if (empty($duplicates)) {
            return response()->json(['status' => 'clear'], 200);
        }

        $typesWithDuplicates = array_column($duplicates, 'gathering_type');
        $totalDuplicates = array_sum(array_column($duplicates, 'duplicates_count'));
        $weekRange = $duplicates[0]['week_range'];
        $typeLabels = array_map(fn ($type) => $this->gatheringLabel($type), $typesWithDuplicates);

        return response()->json([
            'status'                => 'duplicate_detected',
            'requires_confirmation' => true,
            'message'               => 'An attendance entry already exists for ' . implode(', ', $typeLabels) . ' for the week of ' . $weekRange['label'] . '.',
            'gathering_type'        => implode('/', $typesWithDuplicates),
            'week_range'            => $weekRange,
            'duplicates_count'      => $totalDuplicates,
        ], 409);
    }

    private function countImportEntries($user, array $ranges): array
    {
        $applyRanges = static function ($query) use ($ranges): void {
            $query->where(function ($rangeQuery) use ($ranges): void {
                foreach ($ranges as $type => $range) {
                    $start = $range['start']->toDateString();
                    $end = $range['end']->toDateString();
                    $rangeQuery->orWhere(fn ($typeQuery) => $typeQuery
                        ->where('gathering_type', $type)
                        ->inReportingRange([$start, $end]));
                }
            });
        };

        $records = AttendanceRecord::query();
        $applyRanges($records);
        $records->whereHas('student', fn ($query) => $query
            ->where('program', $user->program)
            ->where('year_level', $user->year_level));

        $countByGathering = static fn ($query) => $query
            ->select('gathering_type')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('gathering_type')
            ->pluck('total', 'gathering_type');

        $drafts = AttendanceDraft::query()
            ->where('status', 'draft');
        $applyRanges($drafts);
        $drafts->whereHas('user', fn ($query) => $query
            ->where('program', $user->program)
            ->where('year_level', $user->year_level));

        return [
            'records' => $countByGathering(clone $records),
            'protected' => $countByGathering($this->addProtectedAttendanceStatuses(clone $records)),
            'drafts' => $countByGathering($drafts),
        ];
    }

    /**
     * Paginated, filterable list of attendance records for the table view.
     * GET /api/attendance
     */
  public function index(Request $request)
    {
        $validated = $request->validate([
            'gathering_type' => 'nullable|in:pm,ws,tg',
            'status'         => 'nullable|in:all,validating,pending,approved',
            'date'           => 'nullable|date',
            'sort_by'        => 'nullable|in:day,status,gathering_type',
            'sort_dir'       => 'nullable|in:asc,desc',
            'per_page'       => 'nullable|integer|min:1|max:100',
            'page'           => 'nullable|integer|min:1',
        ]);

        $user = $request->user();
        $program = strtoupper((string) $user->program);
        $yearLevel = (int) $user->year_level;

        $sortBy  = $validated['sort_by']  ?? 'day';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        // --- Part 1: Drafts not yet submitted ---
        $draftsQuery = AttendanceDraft::where('user_id', $user->id)
            ->where('status', 'draft');

        if (!empty($validated['date'])) {
            $draftsQuery->whereDate('updated_at', $validated['date']);
        }

        $drafts = $draftsQuery->get(['id', 'day', 'updated_at', 'gathering_type', 'unsubmitted_at'])->map(function ($draft) use ($program, $yearLevel) {
            return [
                'source'         => 'draft',
                'id'             => 'draft-' . $draft->id,
                'ref_id'         => $draft->id,
                'batch_id'       => null,
                'course'         => $program,
                'year'           => $yearLevel,
                'date'    => optional($draft->day)->format('F j, Y'),
                'submission_date' => optional($draft->updated_at)->format('F j, Y'),
                'raw_submission_at' => $draft->updated_at,
                'day_iso' => optional($draft->day)->toDateString(),
                'raw_day' => $draft->day,
                'gathering_type' => $draft->gathering_type,
                'gathering'      => $this->gatheringLabel($draft->gathering_type ?? ''),
                'time'           => optional($draft->updated_at)->format('g:i A'),
                'status'         => 'validating',
                'unsubmitted_at' => $draft->unsubmitted_at?->toIso8601String(),
            ];
        })->filter(function ($row) use ($validated) {
            if (!empty($validated['gathering_type']) && $row['gathering_type'] !== $validated['gathering_type']) return false;
            if (!empty($validated['status']) && $validated['status'] !== 'all' && $row['status'] !== $validated['status']) return false;
            return true;
        })->values();

        // --- Part 2: Submitted records, grouped by batch_id ---
        $recordsQuery = AttendanceRecord::query()
            ->whereHas('student', function ($q) use ($program, $yearLevel) {
                $q->where('program', $program)->where('year_level', $yearLevel);
            });

        if (!empty($validated['gathering_type'])) {
            $recordsQuery->where('gathering_type', $validated['gathering_type']);
        }
        if (!empty($validated['date'])) {
            $recordsQuery->whereDate('updated_at', $validated['date']);
        }

        // Group rows in TiDB so PHP receives one row per submission batch,
        // rather than downloading every student's attendance row first.
        // Legacy rows remain separated by gathering, date, and submitter.
        $groupExpression = "CASE WHEN batch_id IS NOT NULL AND batch_id <> '' "
            . "THEN CONCAT('batch:', batch_id) "
            . "ELSE CONCAT('legacy:', gathering_type, '|', DATE_FORMAT(day, '%Y-%m-%d'), '|', submitted_by) END";

        $recordsQuery
            ->selectRaw(
                'MAX(batch_id) AS batch_id, COALESCE(MIN(reporting_day), MIN(day)) AS day, MIN(gathering_type) AS gathering_type, '
                . 'MIN(submitted_by) AS submitted_by, MAX(updated_at) AS raw_submission_at, '
                . 'COUNT(*) AS row_count, '
                . 'SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) AS validated_count, '
                . 'SUM(CASE WHEN submitted_by <> ? '
                . 'THEN 1 ELSE 0 END) AS unsubmit_blocker_count',
                ['validated', $user->id]
            )
            ->groupByRaw($groupExpression);

        if (($validated['status'] ?? null) === 'approved') {
            $recordsQuery->havingRaw(
                'SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) = COUNT(*)',
                ['validated']
            );
        } elseif (($validated['status'] ?? null) === 'pending') {
            $recordsQuery->havingRaw(
                'SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) < COUNT(*)',
                ['validated']
            );
        } elseif (!empty($validated['status']) && $validated['status'] !== 'all') {
            // The remaining supported filter is "validating", which applies
            // only to drafts and therefore has no submitted-record matches.
            $recordsQuery->havingRaw('1 = 0');
        }

        $records = $recordsQuery->get()->map(function ($group) use ($program, $yearLevel) {
            $day = Carbon::parse($group->day);
            $submissionTime = Carbon::parse($group->raw_submission_at);

            return [
                'source'            => 'record',
                'id'                => 'record-' . ($group->batch_id ?? ($group->gathering_type . '-' . $day->format('Y-m-d'))),
                'ref_id'            => null,
                'batch_id'          => $group->batch_id,
                'course'            => $program,
                'year'              => $yearLevel,
                'date'              => $day->format('F j, Y'),
                'submission_date'   => $submissionTime->format('F j, Y'),
                'raw_submission_at' => $submissionTime,
                'day_iso'           => $day->toDateString(),
                'raw_day'           => $day,
                'gathering_type'    => $group->gathering_type,
                'gathering'         => $this->gatheringLabel($group->gathering_type),
                'submitted_by_id'   => $group->submitted_by,
                'time'              => $submissionTime->format('g:i A'),
                'status'            => (int) $group->validated_count === (int) $group->row_count
                    ? 'approved'
                    : 'pending',
                'can_unsubmit'      => (int) $group->unsubmit_blocker_count === 0
                    && ((int) $group->validated_count === 0 || AttendanceUnsubmitPolicy::approvedWeekAllowed($day)),
            ];
        })->values();

        // --- Merge, sort, paginate manually ---
        $combined = $drafts->concat($records)
            ->sortBy(
                fn ($row) => match ($sortBy) {
                    'status'         => $row['status'],
                    'gathering_type' => $row['gathering_type'],
                    default          => $row['raw_submission_at'] ?? $row['raw_day'],
                },
                SORT_REGULAR,
                $sortDir === 'desc'
            )
            ->values();

        $perPage  = $validated['per_page'] ?? 12;
        $page     = (int) ($request->query('page', 1));
        $total    = $combined->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, $page), $lastPage);

        $pageItems = $combined->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'status' => 'success',
            'data'   => $pageItems,
            'meta'   => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'total'        => $total,
            ],
        ], 200);
    }

    private function ordinalYear(?int $year): string
    {
        if (!$year) return '';
        $suffix = match (true) {
            $year % 100 >= 11 && $year % 100 <= 13 => 'th',
            $year % 10 === 1 => 'st',
            $year % 10 === 2 => 'nd',
            $year % 10 === 3 => 'rd',
            default => 'th',
        };
        return "{$year}{$suffix} Year";
    }

    public function showGatheringRecords(Request $request)
    {
        $validated = $request->validate([
            'batch_id'       => 'nullable|string',
            'gathering_type' => 'required_without:batch_id|in:pm,ws,tg',
            'day'            => 'required_without:batch_id|date',
        ]);

        $user = $request->user();
        $program = strtoupper((string) $user->program);
        $yearLevel = (int) $user->year_level;

        $query = AttendanceRecord::whereHas('student', function ($q) use ($program, $yearLevel) {
            $q->where('program', $program)->where('year_level', $yearLevel);
        });

        if (!empty($validated['batch_id'])) {
            $query->where('batch_id', $validated['batch_id']);
        } else {
            $query->where('gathering_type', $validated['gathering_type'])
                ->whereDate('day', $validated['day']);
        }

        $records = $query->with('student')->get();

        if ($records->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'No records found.'], 404);
        }

        $students = $records->map(fn ($r) => [
            'student_id'   => $r->student_id,
            'student_name' => $r->student->name ?? 'Unknown',
            'program'      => $r->program ?? $r->student->program ?? 'N/A',
            'year_level'   => $r->year_level ?? $r->student->year_level,
            'status'       => $r->status,
            'venue'        => $r->venue ?: '---',
            'batch'        => $r->batch ? Carbon::parse($r->batch)->format('g:ia') : '---',
            'day'          => $r->day ? $r->day->format('Y-m-d') : '---',
        ]);

        $responded = $students->where('status', 'present')->count();
        $firstRecord = $records->first();
        $submitter = $firstRecord->submitter;
        $reportingDay = AttendanceReportingPeriod::day($records);
        $weekRange = $this->gatheringRangeForDate($firstRecord->gathering_type, $reportingDay);

        $submitterProgram = $submitter?->program ? strtoupper($submitter->program) : '';
        $submitterYear = $this->ordinalYear($submitter?->year_level);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'gathering_type'    => $firstRecord->gathering_type,
                'day'               => $reportingDay->format('F j, Y'),
                'week_range'        => [
                    'start' => $weekRange['start']->toDateString(),
                    'end' => $weekRange['end']->toDateString(),
                    'label' => GatheringCycle::formatRangeLabel($weekRange['start'], $weekRange['end']),
                ],
                'submitted_by'      => [
                    'name' => $submitter?->name ?? 'Unknown',
                    'role' => trim(implode(' · ', array_filter([
                        trim("{$submitterProgram} {$submitterYear}"),
                        'Spiritual Secretary',
                    ]))),
                ],
                'validation_status' => $records->first()->validation_status,
                'total_students'    => $students->count(),
                'responded_count'   => $responded,
                'no_response_count' => $students->count() - $responded,
                'students'          => $students,
            ],
        ], 200);
    }

    private function gatheringLabel(string $type): string
    {
        return match ($type) {
            'pm'      => 'Prayer Meeting',
            'ws'      => 'Worship Service',
            'tg'      => 'Thanksgiving',
            default   => ucfirst($type),
        };
    }

    /** Resolve the scheduled date range containing a submitted gathering date. */
    private function gatheringRangeForDate(string $gatheringType, $day): array
    {
        $date = $day instanceof Carbon ? $day->copy() : Carbon::parse($day);
        return GatheringCycle::getRangesForWeekStart($date)[strtolower($gatheringType)];
    }

    private function mapStatus(string $validationStatus): string
    {
        return match ($validationStatus) {
            'unvalidated' => 'pending',
            'validated'   => 'approved',
            default       => $validationStatus,
        };
    }

    private function addProtectedAttendanceStatuses($query)
    {
        return $query->where(function ($statusQuery) {
            $statusQuery->whereNull('validation_status')
                ->orWhereIn('validation_status', ['unvalidated', 'validated']);
        });
    }

    private function isAttendanceProtected(AttendanceRecord $record): bool
    {
        return $record->validation_status === null
            || in_array($record->validation_status, ['unvalidated', 'validated'], true);
    }

    private function protectedAttendanceResponse(?string $type = null, bool $weeklyImport = false)
    {
        $label = $type ? $this->gatheringLabel($type) : null;
        $message = $label
            ? "{$label} attendance has already been submitted for this week and cannot be overridden or edited."
                . ($weeklyImport ? ' Please select an individual gathering to continue.' : '')
            : 'This attendance has already been submitted and cannot be overridden or edited.';

        return response()->json([
            'status' => 'attendance_locked',
            'message' => $message,
            'gathering_type' => $type,
            'gathering_label' => $label,
        ], 409);
    }

    /**
     * Generate a pre-filled GATRH text template with student names sorted alphabetically.
     */
    public function generateTemplate(Request $request)
    {   
        //Weekly attendance
        $request->validate([
            'gathering_type' => 'nullable|string|in:pm,ws,tg,week',
        ]);

        $user = $request->user();
        $program = strtoupper((string) $user->program);
        $yearLevel = (int) $user->year_level;

        if (!$program || !$yearLevel) {
            return response()->json([
                'message' => 'Your account is not assigned to a program and year level.',
            ], 422);
        }

        $gatheringType = strtoupper($request->gathering_type ?? 'pm');
        $periodRanges = $this->importPeriodCycle($request->input('week_period'));


        if (($request->gathering_type ?? 'pm') === 'week') {
            $result = $this->parsingService->generateWeeklyTemplate($program, $yearLevel, $periodRanges);

            if ($result['count'] === 0) {
                return response()->json([
                    'message' => "No active students found for {$program} Year {$yearLevel}.",
                    'template' => null,
                ], 404);
            }

            return response()->json([
                'message'  => 'Weekly attendance template generated successfully.',
                'program'  => $program,
                'year'     => $yearLevel,
                'count'    => $result['count'],
                'template' => $result['template'],
            ], 200);
        }

        // Fetch students belonging to the program & year level, ordered alphabetically by name
        $students = Student::where('program', $program)
            ->active()
            ->where('year_level', $yearLevel)
            ->orderBy('name', 'asc')
            ->get(['name']);

        if ($students->isEmpty()) {
            return response()->json([
                'message' => "No active students found for {$program} Year {$yearLevel}.",
                'template' => null,
            ], 404);
        }

        // Build header template string
        $template = "--- GATRH ATTENDANCE FORM ---\n";
        $typeLabel = match (strtolower($gatheringType)) { 'PM' => 'Prayer Meeting', 'WS' => 'Worship Service', 'TG' => 'Thanksgiving', default => $gatheringType };
        $range = $periodRanges[strtolower($gatheringType)] ?? $this->parsingService->getApplicableWeekRange(strtolower($gatheringType));
        $rangeLabel = GatheringCycle::formatRangeLabel($range['start'], $range['end']);
        $template .= "Gathering: {$typeLabel}\n";
        $template .= "{$typeLabel} - {$rangeLabel}\n";
        $template .= "\n";
        $template .= "[FILL OUT YOUR DETAILS BELOW]\n";
        $template .= "Name | Batch(Time) | Venue | Day (e.g., Monday, Mon)\n";

        // Append numbered list with student names pre-filled
        foreach ($students as $index => $student) {
            $number = $index + 1;
            $template .= "{$number}. {$student->name} | | | \n";
        }

        return response()->json([
            'message'  => 'Attendance template generated successfully.',
            'program'  => $program,
            'year'     => $yearLevel,
            'count'    => $students->count(),
            'template' => $template,
        ], 200);
    }

    public function parseText(Request $request)
    {
        $request->validate([
            'raw_text'       => 'required|string',
            'gathering_type' => 'nullable|string|in:pm,ws,tg',
            'gathering_id'   => 'nullable|exists:gatherings,id',
            'draft_id'       => 'nullable|exists:attendance_drafts,id',
            'confirm_blank'  => 'nullable|boolean',
            'override'       => 'nullable|boolean',
            'cycle'          => 'nullable',
            'week_range'     => 'nullable',
        ]);

        $user = $request->user();
        $program = $user->program;
        $yearLevel = $user->year_level;

        if (!$program || !$yearLevel) {
            return response()->json([
                'message' => 'Your account is not assigned to a program and year level.',
            ], 422);
        }

        $cycle = $this->importPeriodCycle($request->input('week_period')) ?? $request->input('cycle') ?? $request->input('week_range');

        $metadata = $this->parsingService->extractHeaderMetadata(
            $request->raw_text,
            $program,
            $yearLevel,
            $request->gathering_type ?? 'pm',
            $cycle
        );

        if ($metadata['gathering_type'] !== ($request->gathering_type ?? 'pm')) {
            return response()->json([
                'message' => "Check the gathering type, it doesn't match the one on the parsed text",
            ], 422);
        }

        $selectedRange = $this->parsingService->getApplicableWeekRange($metadata['gathering_type'], $cycle, $request->raw_text);

        $lines = explode("\n", trim($request->raw_text));
        $matchingStudents = $this->parsingService->findStudentsMatchingLines($lines, $metadata['default_date']);
        $parsedSubmissions = [];
        $unmatchedLines = [];
        $outOfCourseStudents = [];
        $inactiveStudents = [];
        $incompleteRecords = [];
        $formatErrorLines = [];
        $hasAnyAttendanceDetail = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_contains($line, '---') || str_contains($line, '[')) {
                continue;
            }

            if (!preg_match('/^\d+[\.\)\-]?\s*(.+)$/i', $line)) {
                continue;
            }

            $result = $this->parsingService->buildRecordFromLine(
                $line,
                $metadata['program'],
                $metadata['year_level'],
                $metadata['default_date'],
                $metadata['gathering_type'],
                $cycle,
                $request->raw_text,
                $matchingStudents,
            );

            if (!empty($result['format_errors'])) {
                $studentName = $result['record']['student_name']
                    ?? ($result['unmatched_data']['extracted_name'] ?? null);

                $formatErrorLines[] = [
                    'student_name' => $studentName,
                    'field'        => $result['format_errors'][0]['field'] ?? null,
                    'raw_line'     => $line,
                    'errors'       => $result['format_errors'],
                ];
            }

            if ($result['matched']) {
                if (isset($parsedSubmissions[$result['record']['student_id']])) {
                    $formatErrorLines[] = [
                        'student_name' => $result['record']['student_name'],
                        'raw_line' => $line,
                        'errors' => [[
                            'field' => 'student',
                            'reason' => 'Duplicate student entry. Keep only one entry for this student before importing.',
                        ]],
                    ];
                    continue;
                }

                $parsedSubmissions[$result['record']['student_id']] = $result['record'];

                $hasBatch = !empty($result['record']['batch']);
                $hasVenue = !empty($result['record']['venue']);
                $hasDate = !empty($result['record']['day']);

                $hasRecordAttendanceDetail = $hasBatch || $hasVenue || $hasDate;
                $hasAnyAttendanceDetail = $hasAnyAttendanceDetail || $hasRecordAttendanceDetail;

                if (empty($result['format_errors']) && $hasRecordAttendanceDetail && (!$hasBatch || !$hasVenue || !$hasDate)) {
                    $incompleteRecords[] = [
                        'student_id'     => $result['record']['student_id'],
                        'student_name'   => $result['record']['student_name'],
                        'raw_line'       => $line,
                        'missing'        => array_values(array_filter([
                            !$hasBatch ? 'batch' : null,
                            !$hasVenue ? 'venue' : null,
                            !$hasDate ? 'date' : null,
                        ])),
                        'current_status' => $result['record']['status'],
                    ];
                }
            } elseif ($result['unmatched_data']) {
                if (!empty($result['inactive_student_data'])) {
                    $inactiveStudents[] = $result['inactive_student_data'];
                } elseif (!empty($result['out_of_course_data'])) {
                    $outOfCourseStudents[] = $result['out_of_course_data'];
                } else {
                    $unmatchedLines[] = $result['unmatched_data'];
                }
            }
        }

        $hasSpecialVenue = collect($parsedSubmissions)->contains(
            fn (array $record): bool => $this->parsingService->isSpecialVenue($record['venue'] ?? null)
        );
        if ($selectedRange['start']->greaterThan(Carbon::today()) && !$hasSpecialVenue) {
            return response()->json([
                'status' => 'future_gathering',
                'message' => 'Attendance cannot be added yet because this gathering’s scheduled date range has not begun.',
            ], 422);
        }

        $missingStudents = $this->parsingService->findMissingStudents(
            $metadata['program'],
            $metadata['year_level'],
            $parsedSubmissions
        );

        $allStudentsNoResponse = !empty($parsedSubmissions)
            && empty($missingStudents)
            && empty($unmatchedLines)
            && !$hasAnyAttendanceDetail
            && collect($parsedSubmissions)->every(
                fn (array $record): bool => $record['status'] === 'no_response'
            );

        $allNoResponseWarning = $allStudentsNoResponse
            ? 'Are you sure the data is correct? This is a completely blank record.'
            : null;

        $confirmBlank = (bool) $request->boolean('confirm_blank');
        $hasHardErrors = !empty($formatErrorLines)
            || !empty($incompleteRecords)
            || !empty($unmatchedLines)
            || !empty($inactiveStudents)
            || !empty($outOfCourseStudents)
            || !empty($missingStudents);

        $isOverride = (bool) $request->boolean('override');
        $range = $this->parsingService->getApplicableWeekRange($metadata['gathering_type'], $cycle, $request->raw_text);
        $startStr = $range['start']->toDateString();
        $endStr = $range['end']->toDateString();

        $existingRecordsQuery = AttendanceRecord::where('gathering_type', $metadata['gathering_type'])
            ->whereHas('student', function ($query) use ($metadata, $program, $yearLevel) {
                $query->where('program', $metadata['program'] ?? $program)
                    ->where('year_level', $metadata['year_level'] ?? $yearLevel);
            })
            ->where(function ($q) use ($startStr, $endStr, $metadata) {
                if (!empty($metadata['default_date'])) {
                    $q->where('day', $metadata['default_date'])
                      ->orWhere(fn ($periodQuery) => $periodQuery->inReportingRange([$startStr, $endStr]));
                } else {
                    $q->inReportingRange([$startStr, $endStr]);
                }
            });
        $existingRecordsCount = (clone $existingRecordsQuery)->count();
        $protectedRecordsCount = $this->addProtectedAttendanceStatuses(clone $existingRecordsQuery)->count();

        if ($protectedRecordsCount > 0) {
            return $this->protectedAttendanceResponse($metadata['gathering_type']);
        }
        $existingDraftsCount = AttendanceDraft::where('gathering_type', $metadata['gathering_type'])
            ->where('status', 'draft')
            ->when($request->draft_id, fn ($query) => $query->where('id', '!=', $request->draft_id))
            ->inReportingRange([$startStr, $endStr])
            ->whereHas('user', function ($query) use ($metadata, $program, $yearLevel) {
                $query->where('program', $metadata['program'] ?? $program)
                    ->where('year_level', $metadata['year_level'] ?? $yearLevel);
            })
            ->count();
        $existingCount = $existingRecordsCount + $existingDraftsCount;

        if ($existingCount > 0 && !$isOverride) {
            $typeLabel = $this->gatheringLabel($metadata['gathering_type']);
            $weekLabel = $range['start']->format('F j') . ' - ' . $range['end']->format('F j, Y');

            return response()->json([
                'status'                => 'duplicate_detected',
                'requires_confirmation' => true,
                'message'               => "An attendance record already exists for {$typeLabel} for the week of {$weekLabel}. Do you want to override existing records?",
                'gathering_type'        => $metadata['gathering_type'],
                'week_range'            => [
                    'start' => $startStr,
                    'end'   => $endStr,
                    'label' => $weekLabel,
                ],
                'duplicates_count'      => $existingCount,
            ], 409);
        }

        if ($hasHardErrors) {
            return response()->json([
                'message'                 => 'Attendance text contains errors. Please correct them before saving the draft.',
                'metadata'                => $metadata,
                'unmatched_lines'         => $unmatchedLines,
                'inactive_students'       => $inactiveStudents,
                'out_of_course_students' => $outOfCourseStudents,
                'missing_students'        => $missingStudents,
                'incomplete_records'      => $incompleteRecords,
                'format_errors'           => $formatErrorLines,
                'all_no_response_warning' => $allNoResponseWarning,
                'requires_confirmation'   => false,
            ], 422);
        }

        if ($allNoResponseWarning && !$confirmBlank) {
            return response()->json([
                'message'                 => 'All students have blank attendance fields. Confirm to mark them as no response and continue.',
                'metadata'                => $metadata,
                'unmatched_lines'         => [],
                'inactive_students'       => [],
                'missing_students'        => [],
                'incomplete_records'      => [],
                'format_errors'           => [],
                'all_no_response_warning' => $allNoResponseWarning,
                'requires_confirmation'   => true,
            ], 422);
        }

        $sectionData = $this->parsingService->buildFullSectionRecords(
            $metadata['program'],
            $metadata['year_level'],
            $parsedSubmissions,
            $metadata['default_date'],
            $metadata['gathering_type']
        );

        $presentCount = 0;
        foreach ($sectionData['records'] as $record) {
            if ($record['status'] === 'present') {
                $presentCount++;
            }
        }

        $draft = $this->parsingService->saveDraft([
            'draft_id'       => $request->draft_id ?? null,
            'gathering_type' => $metadata['gathering_type'],
            'day'            => $metadata['default_date'],
            'raw_text'       => $request->raw_text,
            'parsed_data'    => $sectionData['records'],
            'unparsed_data'  => $unmatchedLines,
            'unmatched_lines'=> $unmatchedLines,
            'out_of_course_students' => $outOfCourseStudents,
            'incomplete_records' => $incompleteRecords,
            'format_errors'  => $formatErrorLines,
            'total_detected' => $sectionData['total_students'],
            'matched_count'  => $presentCount,
        ], $request->user()->id);

        $this->auditLogService->recordAction(
            'Imported ' . $this->gatheringLabel($metadata['gathering_type']) . ' attendance draft (' . $sectionData['total_students'] . ' students)',
            'attendance_drafts',
            (string) $draft->id,
            $request->user()
        );

        return response()->json([
            'message'  => 'Attendance text parsed successfully and saved as draft.',
            'draft_id' => $draft->id,
            'metadata' => $metadata,
            'summary'  => [
                'gathering_type'      => strtoupper($metadata['gathering_type']),
                'total_students'      => $sectionData['total_students'],
                'present_count'       => $presentCount,
                'no_response_count'   => $sectionData['total_students'] - $presentCount,
                'unmatched_count'     => count($unmatchedLines),
                'missing_count'       => count($missingStudents),
                'incomplete_count'    => count($incompleteRecords),
                'format_error_count'  => count($formatErrorLines),
            ],
            'unmatched_lines'    => $unmatchedLines,
            'inactive_students'  => $inactiveStudents,
            'out_of_course_students' => $outOfCourseStudents,
            'missing_students'   => $missingStudents,
            'incomplete_records' => $incompleteRecords,
            'format_errors'      => $formatErrorLines,
            'records'            => $sectionData['records'],
        ], 200);
    }

    public function parseBatchText(Request $request)
    {
        $request->validate([
            'raw_text'      => 'required|string',
            'confirm_blank' => 'nullable|boolean',
            'override'      => 'nullable|boolean',
            'cycle'         => 'nullable',
            'week_range'    => 'nullable',
        ]);

        $user = $request->user();
        if (!$user->program || !$user->year_level) {
            return response()->json(['message' => 'Your account is not assigned to a program and year level.'], 422);
        }

        $cycle = $this->importPeriodCycle($request->input('week_period')) ?? $request->input('cycle') ?? $request->input('week_range');
        $parsed = $this->parsingService->parseBatchText($request->raw_text, $user->program, $user->year_level, $cycle);
        $confirmBlank = (bool) $request->boolean('confirm_blank');
        $isOverride = (bool) $request->boolean('override');

        $hasHardErrors = !empty($parsed['unmatched_lines'])
            || !empty($parsed['inactive_students'])
            || !empty($parsed['out_of_course_students'])
            || !empty($parsed['format_errors'])
            || !empty($parsed['incomplete_records']);
        $hasSoftWarnings = !empty($parsed['blank_entries']) || !empty($parsed['all_no_response_warning']);

        // Check for existing submissions in this week range FIRST
        $typesWithSubmissions = [];
        $ranges = [];

        foreach (['pm', 'ws', 'tg'] as $type) {
            if (empty($parsed['records'][$type])) continue;

            $ranges[$type] = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->raw_text);
        }

        $existingCounts = $ranges === []
            ? ['records' => collect(), 'protected' => collect(), 'drafts' => collect()]
            : $this->countImportEntries($user, $ranges);

        foreach (['pm', 'ws', 'tg'] as $type) {
            if (!isset($ranges[$type])) continue;

            $existingRecords = (int) ($existingCounts['records'][$type] ?? 0);
            $protectedRecords = (int) ($existingCounts['protected'][$type] ?? 0);

            if ($protectedRecords > 0) {
                return $this->protectedAttendanceResponse($type, true);
            }
            $existingDrafts = (int) ($existingCounts['drafts'][$type] ?? 0);
            $hasExisting = $existingRecords + $existingDrafts > 0;

            if ($hasExisting) {
                $typesWithSubmissions[] = $type;
            }
        }

        if (!empty($typesWithSubmissions) && !$isOverride) {
            $typesLabels = array_map(fn ($t) => $this->gatheringLabel($t), $typesWithSubmissions);
            $range = $ranges[$typesWithSubmissions[0]];
            $weekLabel = $range['start']->format('F j') . ' - ' . $range['end']->format('F j, Y');

            return response()->json([
                'status'                => 'duplicate_detected',
                'requires_confirmation' => true,
                'message'               => "Attendance records already exist for " . implode(', ', $typesLabels) . " for the week of {$weekLabel}. Do you want to override existing records?",
                'gathering_type'        => implode('/', $typesWithSubmissions),
                'week_range'            => [
                    'start' => $range['start']->toDateString(),
                    'end'   => $range['end']->toDateString(),
                    'label' => $weekLabel,
                ],
                'duplicates_count'      => count($typesWithSubmissions),
            ], 409);
        }

        if ($hasHardErrors) {
            return response()->json([
                'message'                 => 'Weekly attendance text contains errors. Please correct them before saving the draft.',
                'unmatched_lines'         => $parsed['unmatched_lines'],
                'inactive_students'       => $parsed['inactive_students'] ?? [],
                'out_of_course_students' => $parsed['out_of_course_students'] ?? [],
                'incomplete_records'      => $parsed['incomplete_records'],
                'format_errors'           => $parsed['format_errors'],
                'blank_entries'           => $parsed['blank_entries'] ?? [],
                'missing_students'        => $parsed['missing_students'],
                'all_no_response_warning' => $parsed['all_no_response_warning'] ?? null,
                'requires_confirmation'   => false,
            ], 422);
        }

        if ($hasSoftWarnings && !$confirmBlank) {
            return response()->json([
                'message'                 => 'Some students have blank attendance fields. Confirm to mark them as no response and continue.',
                'unmatched_lines'         => [],
                'inactive_students'       => [],
                'out_of_course_students' => [],
                'incomplete_records'      => [],
                'format_errors'           => [],
                'blank_entries'           => $parsed['blank_entries'] ?? [],
                'missing_students'        => $parsed['missing_students'],
                'all_no_response_warning' => $parsed['all_no_response_warning'] ?? null,
                'requires_confirmation'   => true,
            ], 422);
        }

        // Either no issues at all, or the user already confirmed — proceed to save drafts.
        $drafts = [];
        $summary = [];

        foreach (['pm', 'ws', 'tg'] as $type) {
            if (empty($parsed['records'][$type])) continue;

            $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->raw_text);
            $startStr = $range['start']->toDateString();
            $endStr = $range['end']->toDateString();

            $hasExisting = AttendanceRecord::where('gathering_type', $type)
                ->whereHas('student', function ($query) use ($user) {
                    $query->where('program', $user->program)
                        ->where('year_level', $user->year_level);
                })
                ->inReportingRange([$startStr, $endStr])
                ->exists() || AttendanceDraft::where('gathering_type', $type)
                    ->where('status', 'draft')
                    ->inReportingRange([$startStr, $endStr])
                    ->whereHas('user', function ($query) use ($user) {
                        $query->where('program', $user->program)
                            ->where('year_level', $user->year_level);
                    })
                    ->exists();

            if ($hasExisting && !$isOverride) {
                $summary[$type] = ['skipped' => true, 'reason' => 'already submitted this week'];
                continue;
            }

            $submissions = collect($parsed['records'][$type])->keyBy('student_id')->all();
            $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->raw_text);
            $day = $range['start']->toDateString();

            $sectionData = $this->parsingService->buildFullSectionRecords(
                strtoupper($user->program), $user->year_level, $submissions, $day, $type
            );
            $presentCount = collect($sectionData['records'])
                ->where('status', 'present')
                ->count();

            $draft = $this->parsingService->saveDraft([
                'gathering_type' => $type,
                'day'            => $day,
                'raw_text'       => $request->raw_text,
                'parsed_data'    => $sectionData['records'],
                'total_detected' => $sectionData['total_students'],
                'matched_count'  => $presentCount,
            ], $user->id);

            $drafts[$type] = $draft->id;
            $summary[$type] = [
                'total_students' => $sectionData['total_students'],
                'present_count'  => $presentCount,
                'no_response_count' => $sectionData['total_students'] - $presentCount,
            ];
        }

        if ($drafts !== []) {
            $gatherings = collect(array_keys($drafts))
                ->map(fn (string $type) => $this->gatheringLabel($type))
                ->implode(', ');

            $this->auditLogService->recordAction(
                "Imported attendance drafts for {$gatherings}",
                'attendance_drafts',
                implode(',', array_values($drafts)),
                $user
            );
        }

        return response()->json([
            'message'   => 'Batch attendance parsed successfully.',
            'drafts'    => $drafts,
            'summary'   => $summary,
            'unmatched' => $parsed['unmatched_lines'] ?? [],
            'inactive_students' => $parsed['inactive_students'] ?? [],
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'draft_id'                  => 'nullable|exists:attendance_drafts,id',
            'records'                  => 'required|array|min:1',
            'records.*.student_id'     => 'required|exists:students,id',
            'records.*.gathering_type' => 'required|in:pm,ws,tg',
            'records.*.day'            => 'required|date_format:Y-m-d|before_or_equal:today'|'max:9',
            'records.*.status'         => 'required|in:present,no_response,excused',
            'records.*.batch'          => 'nullable|string|max:10',
            'records.*.venue'          => 'nullable|string|max:25',
            'override'                 => 'nullable|boolean',
            'cycle'                    => 'nullable',
            'week_range'               => 'nullable',
            'raw_text'                 => 'nullable|string',
        ]);

        // Existing drafts retain their original students after inactivation.
        $sourceDraft = null;
        $preservedStudentIds = [];
        if (!empty($validated['draft_id'])) {
            $sourceDraft = AttendanceDraft::whereKey($validated['draft_id'])
                ->where('user_id', $request->user()->id)->where('status', 'draft')->first();
            if (!$sourceDraft) {
                return response()->json(['message' => 'Editable draft not found.'], 404);
            }
            if (collect($validated['records'])->contains(fn ($record) => $record['gathering_type'] !== $sourceDraft->gathering_type)) {
                return response()->json(['message' => 'Submit the gathering saved in this draft.'], 422);
            }
            $preservedStudentIds = collect($sourceDraft->parsed_data ?? [])->pluck('student_id')->all();
        }

        $gatheringTypes = collect($validated['records'])->pluck('gathering_type')->unique();
        $studentIds = collect($validated['records'])->pluck('student_id')->unique();
        $accessibleStudentCount = Student::whereKey($studentIds)
            ->where(fn ($students) => $students->active()->orWhereIn('students.id', $preservedStudentIds))
            ->accessibleTo($request->user())
            ->count();

        if ($accessibleStudentCount !== $studentIds->count()) {
            $inactiveStudents = Student::whereKey($studentIds->all())
                ->where('is_active', false)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Student $student) => ['student_id' => $student->id, 'student_name' => $student->name])
                ->values();

            if ($inactiveStudents->isNotEmpty()) {
                $message = $inactiveStudents->count() === 1
                    ? "{$inactiveStudents->first()['student_name']} is already inactive and cannot be included in this attendance submission."
                    : 'These students are already inactive and cannot be included in this attendance submission: '
                        . $inactiveStudents->pluck('student_name')->implode(', ')
                        . '.';

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                    'inactive_students' => $inactiveStudents,
                ], 403);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to submit attendance for one or more students.',
            ], 403);
        }

        // Preserve the student's course and year at the time attendance is submitted.
        $studentsById = Student::whereKey($studentIds)
            ->get(['id', 'program', 'year_level'])
            ->keyBy('id');

        $submittedBy = $request->user()->id;
        $isOverride = (bool) $request->boolean('override');
        $cycle = $this->importPeriodCycle($request->input('week_period')) ?? $request->input('cycle') ?? $request->input('week_range');
        if ($sourceDraft) {
            $cycle = GatheringCycle::getRangesForWeekStart(Carbon::parse($sourceDraft->day));
        }

        $duplicateRecords = [];
        $recordsToProcess = [];

        foreach ($validated['records'] as $index => $recordData) {
            $type = $recordData['gathering_type'];
            $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->input('raw_text'));
            $startStr = $range['start']->toDateString();
            $endStr = $range['end']->toDateString();

            // Check if attendance already exists for this student & gathering type in the applicable week
            $recordData['reporting_day'] = $startStr;
            $existing = AttendanceRecord::where('student_id', $recordData['student_id'])
                ->where('gathering_type', $type)
                ->where(function ($q) use ($recordData, $startStr, $endStr) {
                    $q->where('day', $recordData['day'])
                      ->orWhere(fn ($periodQuery) => $periodQuery->inReportingRange([$startStr, $endStr]));
                })
                ->with('student')
                ->first();

            if ($existing) {
                $duplicateRecords[] = [
                    'index'              => $index,
                    'student_id'         => $recordData['student_id'],
                    'student_name'       => $existing->student->name ?? 'Unknown',
                    'gathering_type'     => $type,
                    'existing_record_id' => $existing->id,
                    'existing_day'       => $existing->day instanceof Carbon ? $existing->day->toDateString() : (string) $existing->day,
                    'submitted_day'      => $recordData['day'],
                    'week_range'         => [
                        'start' => $startStr,
                        'end'   => $endStr,
                        'label' => $range['start']->format('F j') . ' - ' . $range['end']->format('F j, Y'),
                    ],
                ];
                $recordsToProcess[] = [
                    'data'     => $recordData,
                    'existing' => $existing,
                ];
            } else {
                $recordsToProcess[] = [
                    'data'     => $recordData,
                    'existing' => null,
                ];
            }
        }

        if ($isOverride && collect($recordsToProcess)->contains(function (array $item): bool {
            return $item['existing'] instanceof AttendanceRecord
                && $this->isAttendanceProtected($item['existing']);
        })) {
            $protectedType = collect($recordsToProcess)->first(function (array $item): bool {
                return $item['existing'] instanceof AttendanceRecord
                    && $this->isAttendanceProtected($item['existing']);
            })['gathering_type'] ?? null;
            return $this->protectedAttendanceResponse($protectedType, $request->input('gathering_type') === 'week');
        }

        if (empty($duplicateRecords) && !$isOverride) {
            foreach ($gatheringTypes as $type) {
                $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->input('raw_text'));
                $startStr = $range['start']->toDateString();
                $endStr = $range['end']->toDateString();
                $weekLabel = $range['start']->format('F j') . ' - ' . $range['end']->format('F j, Y');

                $sectionHasRecords = AttendanceRecord::where('gathering_type', $type)
                    ->whereHas('student', function ($query) use ($request) {
                        $query->where('program', $request->user()->program)
                            ->where('year_level', $request->user()->year_level);
                    })
                    ->inReportingRange([$startStr, $endStr])
                    ->exists();
                $sectionHasDrafts = AttendanceDraft::where('gathering_type', $type)
                    ->where('status', 'draft')
                    ->when($validated['draft_id'] ?? null, fn ($query, $draftId) => $query->where('id', '!=', $draftId))
                    ->inReportingRange([$startStr, $endStr])
                    ->whereHas('user', function ($query) use ($request) {
                        $query->where('program', $request->user()->program)
                            ->where('year_level', $request->user()->year_level);
                    })
                    ->exists();

                if ($sectionHasRecords || $sectionHasDrafts) {
                    return response()->json([
                        'status'                => 'duplicate_detected',
                        'requires_confirmation' => true,
                        'message'               => "An attendance record already exists for " . $this->gatheringLabel($type) . " for the week of {$weekLabel}. Do you want to override existing records?",
                        'gathering_type'        => $type,
                        'week_range'            => [
                            'start' => $startStr,
                            'end'   => $endStr,
                            'label' => $weekLabel,
                        ],
                        'duplicates_count'      => count($validated['records']),
                        'duplicate_records'     => [],
                    ], 409);
                }
            }
        }

        if (!$isOverride && !empty($duplicateRecords)) {
            $firstDup = $duplicateRecords[0];
            $typeLabel = $this->gatheringLabel($firstDup['gathering_type']);
            $weekLabel = $firstDup['week_range']['label'];

            return response()->json([
                'status'                => 'duplicate_detected',
                'requires_confirmation' => true,
                'message'               => "An attendance record already exists for {$typeLabel} for the week of {$weekLabel}. Do you want to override existing records?",
                'gathering_type'        => $firstDup['gathering_type'],
                'week_range'            => $firstDup['week_range'],
                'duplicates_count'      => count($duplicateRecords),
                'duplicate_records'     => $duplicateRecords,
            ], 409);
        }

        // --- Atomic Bulk Insert / Update within DB Transaction ---
        try {
            $savedRecords = DB::transaction(function () use ($recordsToProcess, $submittedBy, $validated, $request, $isOverride, $studentsById, $sourceDraft) {
            $records = [];
            $unsubmittedAt = $sourceDraft?->unsubmitted_at;
            $resubmittedAt = $unsubmittedAt ? now() : null;
            $overriddenCount = 0;
            $createdCount = 0;
            $batchId = (string) \Illuminate\Support\Str::uuid();

            foreach ($recordsToProcess as $item) {
                $recordData = $item['data'];
                $existing = $item['existing'];
                $studentSnapshot = $studentsById->get($recordData['student_id']);
                $formattedBatch = $this->parsingService->formatBatchTime($recordData['batch'] ?? null);

                if ($existing && $isOverride) {
                    $existing = AttendanceRecord::whereKey($existing->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (!$existing || $this->isAttendanceProtected($existing)) {
                        throw new \DomainException('Attendance is pending or validated.');
                    }

                    $existing->update([
                        'batch_id'          => $batchId,
                        'program'           => $studentSnapshot?->program,
                        'year_level'        => $studentSnapshot?->year_level,
                        'day'               => $recordData['day'],
                        'reporting_day'     => $recordData['reporting_day'],
                        'batch'             => $formattedBatch,
                        'venue'             => isset($recordData['venue']) ? trim($recordData['venue']) : null,
                        'status'            => $recordData['status'],
                        'validation_status' => 'unvalidated',
                        'validated_by'      => null,
                        'approved_at'       => null,
                        'unsubmitted_at'    => $unsubmittedAt,
                        'resubmitted_at'    => $resubmittedAt,
                        'submitted_by'      => $submittedBy,
                    ]);
                    $records[] = $existing->fresh();
                    $overriddenCount++;
                } else {
                    $records[] = AttendanceRecord::create([
                        'batch_id'          => $batchId,
                        'student_id'        => $recordData['student_id'],
                        'program'           => $studentSnapshot?->program,
                        'year_level'        => $studentSnapshot?->year_level,
                        'gathering_type'    => $recordData['gathering_type'],
                        'day'               => $recordData['day'],
                        'reporting_day'     => $recordData['reporting_day'],
                        'batch'             => $formattedBatch,
                        'venue'             => isset($recordData['venue']) ? trim($recordData['venue']) : null,
                        'status'            => $recordData['status'],
                        'validation_status' => 'unvalidated',
                        'unsubmitted_at'    => $unsubmittedAt,
                        'resubmitted_at'    => $resubmittedAt,
                        'submitted_by'      => $submittedBy,
                    ]);
                    $createdCount++;
                }
            }

            $draftQuery = AttendanceDraft::where('user_id', $request->user()->id)
                ->where('status', 'draft');

            if (!empty($validated['draft_id'])) {
                $draftQuery->where('id', $validated['draft_id']);
            } else {
                $draftQuery->latest('updated_at');
            }

            $draft = $draftQuery->first();
            $draft?->delete();

            // Clear secretary gathering reminder
            collect($recordsToProcess)
                ->map(fn (array $item) => [
                    'gathering_type' => $item['data']['gathering_type'],
                    'day' => Carbon::parse($item['data']['day'])->toDateString(),
                ])
                ->unique(fn (array $r) => $r['gathering_type'] . '|' . $r['day'])
                ->each(function (array $r) use ($submittedBy): void {
                    Reminder::query()
                        ->where('user_id', $submittedBy)
                        ->where('reminder_type', 'gathering')
                        ->where('gathering_type', $r['gathering_type'])
                        ->whereDate('gathering_date', $r['day'])
                        ->delete();
                });

            return [
                'records'          => $records,
                'overridden_count' => $overriddenCount,
                'created_count'    => $createdCount,
                'skipped_count'    => 0,
            ];
            });
        } catch (\DomainException $exception) {
            $protectedType = collect($recordsToProcess)->first(function (array $item): bool {
                return $item['existing'] instanceof AttendanceRecord
                    && $this->isAttendanceProtected($item['existing']);
            })['gathering_type'] ?? null;
            return $this->protectedAttendanceResponse($protectedType, $request->input('gathering_type') === 'week');
        }

        $recordCount = count($savedRecords['records']);
        $gatherings = collect($savedRecords['records'])
            ->pluck('gathering_type')
            ->unique()
            ->map(fn (string $type) => $this->gatheringLabel($type))
            ->implode(', ');
        $batchId = $savedRecords['records'][0]->batch_id ?? 'unknown';

        $this->auditLogService->recordAction(
            "Submitted {$gatherings} attendance ({$recordCount} records)",
            'attendance_records',
            (string) $batchId,
            $request->user()
        );

        return response()->json([
            'status'           => 'success',
            'message'          => $savedRecords['overridden_count'] > 0
                ? "Attendance batch submitted successfully: {$savedRecords['overridden_count']} record(s) overridden, {$savedRecords['created_count']} record(s) created."
                : 'Attendance batch submitted successfully and flagged for validation.',
            'count'            => count($savedRecords['records']),
            'overridden_count' => $savedRecords['overridden_count'],
            'created_count'    => $savedRecords['created_count'],
            'skipped_count'    => $savedRecords['skipped_count'],
            'records'          => $savedRecords['records'],
        ], 201);
    }

    public function reviewSubmission(Request $request, string $batchKey)
    {
        $user = $request->user();

        $query = AttendanceRecord::query()
            ->with(['student', 'validator:id,name,role,program,year_level'])
            ->whereHas('student', fn ($q) => $q->accessibleTo($user));

        // batchKey is either a real batch_id (UUID) or our legacy fallback "type|day|submittedBy"
        if (str_contains($batchKey, '|')) {
            [$gatheringType, $day, $submittedBy] = explode('|', $batchKey, 3);
            $query->where('gathering_type', $gatheringType)
                ->whereDate('day', $day)
                ->where('submitted_by', $submittedBy);
        } else {
            $query->where('batch_id', $batchKey);
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'Submission not found.'], 404);
        }

        $first = $records->first();
        $submitter = $first->submitter;
        $reportingDay = AttendanceReportingPeriod::day($records);
        $weekRange = $this->gatheringRangeForDate($first->gathering_type, $reportingDay);

        $students = $records->map(fn ($r) => [
            'record_id'    => $r->id,
            'day'          => $r->day?->toDateString(),
            'student_id'   => $r->student_id,
            'student_name' => $r->student->name ?? 'Unknown',
            'program'      => $r->program ?? $r->student->program ?? 'N/A',
            'year_level'   => $r->year_level ?? $r->student->year_level,
            'status'       => $r->status,
            'venue'        => $r->venue ?: '---',
            'batch'        => $r->batch ? Carbon::parse($r->batch)->format('g:ia') : '---',
        ]);
        return response()->json([
            'status' => 'success',
            'data'   => [
                'batch_key'         => $batchKey,
                'gathering_type'    => $first->gathering_type,
                'gathering'         => $this->gatheringLabel($first->gathering_type),
                'day'               => $reportingDay->format('F j, Y'),
                'submission_date'   => $first->created_at?->format('F j, Y') ?? '—',
                'unsubmitted_at'    => $first->unsubmitted_at?->toIso8601String(),
                'resubmitted_at'    => $first->resubmitted_at?->toIso8601String(),
                'week_range'        => [
                    'start' => $weekRange['start']->toDateString(),
                    'end' => $weekRange['end']->toDateString(),
                    'label' => GatheringCycle::formatRangeLabel($weekRange['start'], $weekRange['end']),
                ],
                'time'              => $first->created_at?->format('g:i A') ?? '—',
                'validation_status' => $first->validation_status,
                'submitted_by'      => [
                    'name' => $submitter?->name ?? 'Unknown',
                    'role' => trim(strtoupper($submitter?->program ?? '') . ' ' . $this->ordinalYear($submitter?->year_level)),
                ],
                'approved_by'       => $first->validator ? [
                    'name' => $first->validator->name,
                    'role' => $first->validator->role,
                    'program' => $first->validator->program,
                ] : null,
                'total_students'    => $students->count(),
                'responded_count'   => $students->where('status', 'present')->count(),
                'students'          => $students,
            ],
        ], 200);
    }

    /**
     * Validate all records belonging to a specific gathering session.
     */
    public function approveSubmission(Request $request, string $batchKey)
    {
        $query = AttendanceRecord::query();

        if (str_contains($batchKey, '|')) {
            [$gatheringType, $day, $submittedBy] = explode('|', $batchKey, 3);
            $query->where('gathering_type', $gatheringType)
                ->whereDate('day', $day)
                ->where('submitted_by', $submittedBy);
        } else {
            $query->where('batch_id', $batchKey);
        }

        $query->whereHas('student', fn ($studentQuery) => $studentQuery->accessibleTo($request->user()))
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('validation_status')->orWhere('validation_status', 'unvalidated');
            });

        $updatedCount = $query->toBase()->update([
            'validation_status' => 'validated',
            'validated_by'      => $request->user()->id,
            'approved_at'       => now(),
        ]);

        if ($updatedCount === 0) {
            return response()->json(['status' => 'error', 'message' => 'Submission not found.'], 404);
        }

        $this->auditLogService->recordAction(
            "Approved attendance batch ({$updatedCount} records)",
            'attendance_records',
            $batchKey,
            $request->user()
        );

        return response()->json([
            'status'        => 'success',
            'message'       => "Submission approved across {$updatedCount} records.",
            'updated_count' => $updatedCount,
        ], 200);
    }

    /** Move a secretary's pending attendance submission back into editable drafts. */
    public function unsubmitSubmission(Request $request, string $batchKey)
    {
        $user = $request->user();
        $query = AttendanceRecord::query()
            ->where('submitted_by', $user->id)
            ->whereHas('student', fn ($studentQuery) => $studentQuery->accessibleTo($user));

        if (str_contains($batchKey, '|')) {
            [$gatheringType, $day, $submittedBy] = explode('|', $batchKey, 3);
            if ((int) $submittedBy !== (int) $user->id) {
                return response()->json(['status' => 'error', 'message' => 'You can only unsubmit your own attendance records.'], 403);
            }
            $query->where('gathering_type', $gatheringType)->whereDate('day', $day);
        } else {
            $query->where('batch_id', $batchKey);
        }

        try {
            $result = DB::transaction(function () use ($query, $user) {
                $records = $query->with('student')->lockForUpdate()->get();
                if ($records->isEmpty()) {
                    throw new \DomainException('Submission not found or it is no longer available to unsubmit.', 404);
                }

                $createdDrafts = [];
                foreach ($records->groupBy('gathering_type') as $type => $group) {
                    if ($group->contains(fn ($record) => $record->validation_status === 'validated')
                        && !AttendanceUnsubmitPolicy::approvedWeekAllowed(AttendanceReportingPeriod::day($group))) {
                        throw new \DomainException('Approved attendance can only be unsubmitted for the current or previous reporting week.', 409);
                    }
                    $gatheringRange = GatheringCycle::getRangesForWeekStart(AttendanceReportingPeriod::day($group))[strtolower($type)];
                    $rangeStart = $gatheringRange['start'];
                    $rangeEnd = $gatheringRange['end'];

                    if (AttendanceDraft::where('user_id', $user->id)
                        ->where('gathering_type', $type)
                        ->whereDate('day', $rangeStart->toDateString())
                        ->where('status', 'draft')
                        ->exists()) {
                        throw new \DomainException("An editable {$this->gatheringLabel($type)} draft already exists for this week.", 409);
                    }

                    $label = GatheringCycle::formatRangeLabel($rangeStart, $rangeEnd);
                    $gathering = $this->gatheringLabel($type);
                    $parsedData = $group->map(fn (AttendanceRecord $record) => [
                        'student_id' => $record->student_id,
                        'student_name' => $record->student?->name ?? 'Unknown',
                        'program' => $record->program ?? $record->student?->program,
                        'year_level' => $record->year_level ?? $record->student?->year_level,
                        'gathering_type' => $type,
                        // A no-response row has a required database day, but it is not
                        // an attendance date the secretary entered. Keep it blank in the draft.
                        'day' => $record->status === 'no_response'
                            ? null
                            : Carbon::parse($record->day)->toDateString(),
                        'batch' => $record->batch,
                        'venue' => $record->venue,
                        'status' => $record->status,
                        'is_manual' => false,
                    ])->values()->all();

                    $createdDrafts[] = AttendanceDraft::create([
                        'user_id' => $user->id,
                        'gathering_type' => $type,
                        'day' => $rangeStart->toDateString(),
                        'raw_text' => "--- GATRH ATTENDANCE FORM ---\nGathering: {$gathering}\n{$gathering} - {$label}\n",
                        'parsed_data' => $parsedData,
                        'total_detected' => count($parsedData),
                        'matched_count' => collect($parsedData)->where('status', 'present')->count(),
                        'status' => 'draft',
                        'unsubmitted_at' => now(),
                    ]);
                }

                // Preserve deletion observers, including escalation refresh.
                foreach ($records as $record) {
                    $record->delete();
                }
                return ['drafts' => $createdDrafts, 'record_count' => $records->count()];
            });
        } catch (\DomainException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], $exception->getCode() ?: 409);
        }

        $this->auditLogService->recordAction(
            'Unsubmitted attendance submission',
            'attendance_records',
            $batchKey,
            $user,
            'success',
            json_encode(['submissions' => collect($result['drafts'])->flatMap(fn ($draft) =>
                collect($draft->parsed_data ?? [])->map(fn ($entry) => [
                    'program' => $entry['program'] ?? $user->program,
                    'year_level' => $entry['year_level'] ?? $user->year_level,
                    'gathering_type' => $draft->gathering_type,
                    'reporting_day' => $draft->day->toDateString(),
                ])->unique(fn ($entry) => $entry['program'].'|'.$entry['year_level'])
            )->values()->all()])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Attendance returned to Validating. You can edit and submit it again.',
            'draft_ids' => collect($result['drafts'])->pluck('id'),
            'returned_count' => $result['record_count'],
        ]);
    }

    /**
     * Summary analytics for higher management (Coordinator / Chancellor).
     */
    public function summaryReport(Request $request)
    {
        // Validate request inputs
        $validated = $request->validate([
            'program'   => 'nullable|string|max:50',
            'year_level' => 'nullable|integer|min:1|max:4',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        $program   = $validated['program'] ?? null;
        $yearLevel = $validated['year_level'] ?? null;

        // Base query filtered by Student relation
        $query = AttendanceRecord::query();

        if ($program || $yearLevel) {
            $query->whereHas('student', function ($q) use ($program, $yearLevel) {
                if ($program) {
                    $q->where('program', $program);
                }
                if ($yearLevel) {
                    $q->where('year_level', $yearLevel);
                }
            });
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('day', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('day', '<=', $validated['date_to']);
        }

        // Execute single-query SQL conditional aggregation
        $metrics = $query->selectRaw("
            COUNT(*) as total_records,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'no_response' THEN 1 ELSE 0 END) as no_response_count,
            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count
        ")->first();

        $totalRecords    = (int) ($metrics->total_records ?? 0);
        $presentCount    = (int) ($metrics->present_count ?? 0);
        $noResponseCount = (int) ($metrics->no_response_count ?? 0);
        $excusedCount    = (int) ($metrics->excused_count ?? 0);

        $attendanceRate = $totalRecords > 0 
            ? round(($presentCount / $totalRecords) * 100, 2) 
            : 0;

        return response()->json([
            'status' => 'success',
            'filters' => [
                'program'    => $program ?? 'All Programs',
                'year_level' => $yearLevel ?? 'All Years',
                'date_from'  => $validated['date_from'] ?? null,
                'date_to'    => $validated['date_to'] ?? null,
            ],
            'data' => [
                'total_records'     => $totalRecords,
                'present_count'     => $presentCount,
                'no_response_count' => $noResponseCount,
                'excused_count'     => $excusedCount,
                'attendance_rate'   => $attendanceRate . '%',
            ],
        ], 200);
    }
}
