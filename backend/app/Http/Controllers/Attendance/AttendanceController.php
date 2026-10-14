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
        $cycle = $request->input('cycle') ?? $request->input('week_range');
        $types = $validated['gathering_type'] === 'week'
            ? ['pm', 'ws', 'tg']
            : [$validated['gathering_type']];
        $duplicates = [];

        foreach ($types as $type) {
            $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $validated['raw_text']);
            $start = $range['start']->toDateString();
            $end = $range['end']->toDateString();

            $recordCount = AttendanceRecord::where('gathering_type', $type)
                ->whereBetween('day', [$start, $end])
                ->whereHas('student', function ($query) use ($user) {
                    $query->where('program', $user->program)
                        ->where('year_level', $user->year_level);
                })
                ->count();

            $draftCount = AttendanceDraft::where('gathering_type', $type)
                ->where('status', 'draft')
                ->whereBetween('day', [$start, $end])
                ->whereHas('user', function ($query) use ($user) {
                    $query->where('program', $user->program)
                        ->where('year_level', $user->year_level);
                })
                ->count();

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

    /**
     * Paginated, filterable list of attendance records for the table view.
     * GET /api/attendance
     */
  public function index(Request $request)
    {
        $validated = $request->validate([
            'gathering_type' => 'nullable|in:pm,ws,tg',
            'status'         => 'nullable|in:all,validating,pending,rejected,approved',
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

        $drafts = $draftsQuery->get()->map(function ($draft) use ($program, $yearLevel) {
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
            ->map(function ($group) use ($program, $yearLevel) {
                $first = $group->first();
                $dayCarbon = $first->day instanceof Carbon
                    ? $first->day
                    : Carbon::parse($first->day);

                return [
                    'source'           => 'record',
                    'id'               => 'record-' . ($first->batch_id ?? ($first->gathering_type . '-' . $dayCarbon->format('Y-m-d'))),
                    'ref_id'           => null,
                    'batch_id'         => $first->batch_id ?? null,
                    'course'           => $program,
                    'year'             => $yearLevel,
                    'date'             => $dayCarbon->format('F j, Y'),
                    'submission_date'  => optional($group->max('updated_at'))->format('F j, Y'),
                    'raw_submission_at' => $group->max('updated_at'),
                    'day_iso'          => $dayCarbon->toDateString(),
                    'raw_day'          => $dayCarbon,
                    'gathering_type'   => $first->gathering_type,
                    'gathering'        => $this->gatheringLabel($first->gathering_type),
                    'time'             => optional($group->max('updated_at'))->format('g:i A'),
                    'status'           => $this->aggregateStatus($group),
                    'rejection_reason' => $first->rejection_reason,
                ];
            })
            ->values();

        if (!empty($validated['status']) && $validated['status'] !== 'all') {
            $records = $records->filter(fn ($r) => $r['status'] === $validated['status'])->values();
        }

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
            'program'      => $r->student->program ?? 'N/A',
            'year_level'   => $r->student->year_level,
            'status'       => $r->status,
            'venue'        => $r->venue ?: '---',
            'batch'        => $r->batch ? Carbon::parse($r->batch)->format('g:ia') : '---',
            'day'          => $r->day ? $r->day->format('Y-m-d') : '---',
        ]);

        $responded = $students->where('status', 'present')->count();
        $submitter = $records->first()->submitter;

        $submitterProgram = $submitter?->program ? strtoupper($submitter->program) : '';
        $submitterYear = $this->ordinalYear($submitter?->year_level);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'gathering_type'    => $records->first()->gathering_type,
                'day'               => $records->first()->day->format('F j, Y'),
                'submitted_by'      => [
                    'name' => $submitter?->name ?? 'Unknown',
                    'role' => trim(implode(' · ', array_filter([
                        trim("{$submitterProgram} {$submitterYear}"),
                        'Spiritual Secretary',
                    ]))),
                ],
                'validation_status' => $records->first()->validation_status,
                'rejection_reason'  => $records->first()->rejection_reason,
                'total_students'    => $students->count(),
                'responded_count'   => $responded,
                'no_response_count' => $students->count() - $responded,
                'students'          => $students,
            ],
        ], 200);
    }

    private function aggregateStatus($group): string
    {
        if ($group->contains('validation_status', 'rejected')) {
            return 'rejected';
        }
        if ($group->every(fn ($r) => $r->validation_status === 'validated')) {
            return 'approved';
        }
        return 'pending';
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

    private function mapStatus(string $validationStatus): string
    {
        return match ($validationStatus) {
            'unvalidated' => 'pending',
            'validated'   => 'approved',
            'rejected'    => 'rejected',
            default       => $validationStatus,
        };
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


        if (($request->gathering_type ?? 'pm') === 'week') {
            $result = $this->parsingService->generateWeeklyTemplate($program, $yearLevel);

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
            ->get();

        if ($students->isEmpty()) {
            return response()->json([
                'message' => "No active students found for {$program} Year {$yearLevel}.",
                'template' => null,
            ], 404);
        }

        // Build header template string
        $template = "--- GATRH ATTENDANCE FORM ---\n";
        $template .= "Gathering: {$gatheringType}\n";
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

        $cycle = $request->input('cycle') ?? $request->input('week_range');

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

        $lines = explode("\n", trim($request->raw_text));
        $parsedSubmissions = [];
        $unmatchedLines = [];
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
                $request->raw_text
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
                $unmatchedLines[] = $result['unmatched_data'];
            }
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
            ? 'Are you sure the data is correct? You cannot pass a completely blank record.'
            : null;

        $confirmBlank = (bool) $request->boolean('confirm_blank');
        $hasHardErrors = !empty($formatErrorLines)
            || !empty($incompleteRecords)
            || !empty($unmatchedLines)
            || !empty($missingStudents);

        $isOverride = (bool) $request->boolean('override');
        $range = $this->parsingService->getApplicableWeekRange($metadata['gathering_type'], $cycle, $request->raw_text);
        $startStr = $range['start']->toDateString();
        $endStr = $range['end']->toDateString();

        $existingRecordsCount = AttendanceRecord::where('gathering_type', $metadata['gathering_type'])
            ->whereHas('student', function ($query) use ($metadata, $program, $yearLevel) {
                $query->where('program', $metadata['program'] ?? $program)
                    ->where('year_level', $metadata['year_level'] ?? $yearLevel);
            })
            ->where(function ($q) use ($startStr, $endStr, $metadata) {
                if (!empty($metadata['default_date'])) {
                    $q->where('day', $metadata['default_date'])
                      ->orWhereBetween('day', [$startStr, $endStr]);
                } else {
                    $q->whereBetween('day', [$startStr, $endStr]);
                }
            })
            ->count();
        $existingDraftsCount = AttendanceDraft::where('gathering_type', $metadata['gathering_type'])
            ->where('status', 'draft')
            ->when($request->draft_id, fn ($query) => $query->where('id', '!=', $request->draft_id))
            ->whereBetween('day', [$startStr, $endStr])
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

        $cycle = $request->input('cycle') ?? $request->input('week_range');
        $parsed = $this->parsingService->parseBatchText($request->raw_text, $user->program, $user->year_level, $cycle);
        $confirmBlank = (bool) $request->boolean('confirm_blank');
        $isOverride = (bool) $request->boolean('override');

        $hasHardErrors = !empty($parsed['unmatched_lines']) || !empty($parsed['format_errors']) || !empty($parsed['incomplete_records']);
        $hasSoftWarnings = !empty($parsed['blank_entries']) || !empty($parsed['all_no_response_warning']);

        // Check for existing submissions in this week range FIRST
        $typesWithSubmissions = [];
        $firstRange = null;

        foreach (['pm', 'ws', 'tg'] as $type) {
            if (empty($parsed['records'][$type])) continue;

            $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->raw_text);
            $startStr = $range['start']->toDateString();
            $endStr = $range['end']->toDateString();

            if (!$firstRange) {
                $firstRange = $range;
            }

            $existingRecords = AttendanceRecord::where('gathering_type', $type)
                ->whereHas('student', function ($query) use ($user) {
                    $query->where('program', $user->program)
                        ->where('year_level', $user->year_level);
                })
                ->whereBetween('day', [$startStr, $endStr])
                ->count();
            $existingDrafts = AttendanceDraft::where('gathering_type', $type)
                ->where('status', 'draft')
                ->whereBetween('day', [$startStr, $endStr])
                ->whereHas('user', function ($query) use ($user) {
                    $query->where('program', $user->program)
                        ->where('year_level', $user->year_level);
                })
                ->count();
            $hasExisting = $existingRecords + $existingDrafts > 0;

            if ($hasExisting) {
                $typesWithSubmissions[] = $type;
            }
        }

        if (!empty($typesWithSubmissions) && !$isOverride) {
            $typesLabels = array_map(fn ($t) => $this->gatheringLabel($t), $typesWithSubmissions);
            $range = $firstRange ?? $this->parsingService->getApplicableWeekRange($typesWithSubmissions[0], $cycle, $request->raw_text);
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
                ->whereBetween('day', [$startStr, $endStr])
                ->exists() || AttendanceDraft::where('gathering_type', $type)
                    ->where('status', 'draft')
                    ->whereBetween('day', [$startStr, $endStr])
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
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'draft_id'                  => 'nullable|exists:attendance_drafts,id',
            'records'                  => 'required|array|min:1',
            'records.*.student_id'     => 'required|exists:students,id',
            'records.*.gathering_type' => 'required|in:pm,ws,tg',
            'records.*.day'            => 'required|date',
            'records.*.status'         => 'required|in:present,no_response,excused',
            'records.*.batch'          => 'nullable|string',
            'records.*.venue'          => 'nullable|string',
            'override'                 => 'nullable|boolean',
            'cycle'                    => 'nullable',
            'week_range'               => 'nullable',
            'raw_text'                 => 'nullable|string',
        ]);

        $gatheringTypes = collect($validated['records'])->pluck('gathering_type')->unique();
        $studentIds = collect($validated['records'])->pluck('student_id')->unique();
        $accessibleStudentCount = Student::whereKey($studentIds)
            ->active()
            ->accessibleTo($request->user())
            ->count();

        if ($accessibleStudentCount !== $studentIds->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to submit attendance for one or more students.',
            ], 403);
        }

        $submittedBy = $request->user()->id;
        $isOverride = (bool) $request->boolean('override');
        $cycle = $request->input('cycle') ?? $request->input('week_range');

        $duplicateRecords = [];
        $recordsToProcess = [];

        foreach ($validated['records'] as $index => $recordData) {
            $type = $recordData['gathering_type'];
            $range = $this->parsingService->getApplicableWeekRange($type, $cycle, $request->input('raw_text'));
            $startStr = $range['start']->toDateString();
            $endStr = $range['end']->toDateString();

            // Check if attendance already exists for this student & gathering type in the applicable week
            $existing = AttendanceRecord::where('student_id', $recordData['student_id'])
                ->where('gathering_type', $type)
                ->where(function ($q) use ($recordData, $startStr, $endStr) {
                    $q->where('day', $recordData['day'])
                      ->orWhereBetween('day', [$startStr, $endStr]);
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
                    ->whereBetween('day', [$startStr, $endStr])
                    ->exists();
                $sectionHasDrafts = AttendanceDraft::where('gathering_type', $type)
                    ->where('status', 'draft')
                    ->when($validated['draft_id'] ?? null, fn ($query, $draftId) => $query->where('id', '!=', $draftId))
                    ->whereBetween('day', [$startStr, $endStr])
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
        $savedRecords = DB::transaction(function () use ($recordsToProcess, $submittedBy, $validated, $request, $isOverride) {
            $records = [];
            $overriddenCount = 0;
            $createdCount = 0;
            $batchId = (string) \Illuminate\Support\Str::uuid();

            foreach ($recordsToProcess as $item) {
                $recordData = $item['data'];
                $existing = $item['existing'];
                $formattedBatch = $this->parsingService->formatBatchTime($recordData['batch'] ?? null);

                if ($existing && $isOverride) {
                    $existing->update([
                        'batch_id'          => $batchId,
                        'day'               => $recordData['day'],
                        'batch'             => $formattedBatch,
                        'venue'             => isset($recordData['venue']) ? trim($recordData['venue']) : null,
                        'status'            => $recordData['status'],
                        'validation_status' => 'unvalidated',
                        'submitted_by'      => $submittedBy,
                    ]);
                    $records[] = $existing->fresh();
                    $overriddenCount++;
                } else {
                    $records[] = AttendanceRecord::create([
                        'batch_id'          => $batchId,
                        'student_id'        => $recordData['student_id'],
                        'gathering_type'    => $recordData['gathering_type'],
                        'day'               => $recordData['day'],
                        'batch'             => $formattedBatch,
                        'venue'             => isset($recordData['venue']) ? trim($recordData['venue']) : null,
                        'status'            => $recordData['status'],
                        'validation_status' => 'unvalidated',
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
            ->with('student')
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

        $students = $records->map(fn ($r) => [
            'record_id'    => $r->id,
            'student_id'   => $r->student_id,
            'student_name' => $r->student->name ?? 'Unknown',
            'program'      => $r->student->program ?? 'N/A',
            'year_level'   => $r->student->year_level,
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
                'day'               => $first->day->format('F j, Y'),
                'time'              => $first->created_at?->format('g:i A') ?? '—',
                'validation_status' => $first->validation_status,
                'submitted_by'      => [
                    'name' => $submitter?->name ?? 'Unknown',
                    'role' => trim(strtoupper($submitter?->program ?? '') . ' ' . $this->ordinalYear($submitter?->year_level)),
                ],
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
            'rejection_reason'  => null, // clear any stale reason if this was previously rejected
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
