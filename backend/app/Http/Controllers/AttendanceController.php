<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\AttendanceRecord;
use App\Models\AttendanceDraft;
use App\Models\Student;
use App\Services\AttendanceParsingService;

class AttendanceController extends Controller
{
    protected AttendanceParsingService $parsingService;

    public function __construct(AttendanceParsingService $parsingService)
    {
        $this->parsingService = $parsingService;
    }

    /**
     * Paginated, filterable list of attendance records for the table view.
     * GET /api/attendance
     */
  public function index(Request $request)
    {
        $validated = $request->validate([
            'gathering_type' => 'nullable|in:pm,ws,tg,special',
            'status'         => 'nullable|in:validating,pending,rejected,approved',
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
            $draftsQuery->whereDate('day', $validated['date']);
        }

        $drafts = $draftsQuery->get()->map(function ($draft) use ($program, $yearLevel) {
            return [
                'source'         => 'draft',
                'id'             => 'draft-' . $draft->id,
                'ref_id'         => $draft->id,
                'batch_id'       => null,
                'course'         => $program,
                'year'           => $yearLevel,
                'date'           => optional($draft->updated_at)->format('F j, Y'),
                'day_iso'        => optional($draft->updated_at)->toDateString(),
                'raw_day'        => $draft->updated_at,
                'gathering_type' => $draft->gathering_type,
                'gathering'      => $this->gatheringLabel($draft->gathering_type ?? ''),
                'time'           => optional($draft->updated_at)->format('g:i A'),
                'status'         => 'validating',
            ];
        })->filter(function ($row) use ($validated) {
            if (!empty($validated['gathering_type']) && $row['gathering_type'] !== $validated['gathering_type']) return false;
            if (!empty($validated['status']) && $row['status'] !== $validated['status']) return false;
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
            $recordsQuery->whereDate('day', $validated['date']);
        }

        $records = $recordsQuery->get()
            ->groupBy(function ($r) {
                // Group primarily by batch_id; fallback to gathering_type|day for legacy records
                if (!empty($r->batch_id)) {
                    return $r->batch_id;
                }
                
                $dayFormatted = $r->day instanceof \Carbon\Carbon 
                    ? $r->day->format('Y-m-d') 
                    : \Carbon\Carbon::parse($r->day)->format('Y-m-d');

                return $r->gathering_type . '|' . $dayFormatted;
            })
            ->map(function ($group) use ($program, $yearLevel) {
                $first = $group->first();
                
                $dayCarbon = $first->day instanceof \Carbon\Carbon 
                    ? $first->day 
                    : \Carbon\Carbon::parse($first->day);

                return [
                    'source'         => 'record',
                    'id'             => 'record-' . ($first->batch_id ?? ($first->gathering_type . '-' . $dayCarbon->format('Y-m-d'))),
                    'ref_id'         => null,
                    'batch_id'       => $first->batch_id ?? null,
                    'course'         => $program,
                    'year'           => $yearLevel,
                    'date'           => $dayCarbon->format('F j, Y'),
                    'day_iso'        => $dayCarbon->toDateString(),
                    'raw_day'        => $dayCarbon,
                    'gathering_type' => $first->gathering_type,
                    'gathering'      => $this->gatheringLabel($first->gathering_type),
                    'time'           => optional($first->created_at)->format('g:i A'),
                    'status'         => $this->aggregateStatus($group),
                ];
            })
            ->values();

        if (!empty($validated['status'])) {
            $records = $records->filter(fn ($r) => $r['status'] === $validated['status'])->values();
        }

        // --- Merge, sort, paginate manually ---
        $combined = $drafts->concat($records)
            ->sortBy(
                fn ($row) => match ($sortBy) {
                    'status'         => $row['status'],
                    'gathering_type' => $row['gathering_type'],
                    default          => $row['raw_day'],
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
            'gathering_type' => 'required_without:batch_id|in:pm,ws,tg,special',
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
            'special' => 'Special Gathering',
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
            'gathering_type' => 'nullable|string|in:pm,ws,tg,special,week',
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
        $template .= "Name | Batch(Time) | Venue | Date(YYYY-MM-DD)\n";

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
            'raw_text'     => 'required|string',
            'gathering_type'=> 'nullable|string|in:pm,ws,tg,special',
            'gathering_id' => 'nullable|exists:gatherings,id',
            'draft_id'     => 'nullable|exists:attendance_drafts,id',
        ]);

        $user = $request->user();
        $program = $user->program;
        $yearLevel = $user->year_level;

        if (!$program || !$yearLevel) {
            return response()->json([
                'message' => 'Your account is not assigned to a program and year level.',
            ], 422);
        }

        $metadata = $this->parsingService->extractHeaderMetadata(
            $request->raw_text,
            $program,
            $yearLevel,
            $request->gathering_type ?? 'pm'
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
                $metadata['gathering_type']
            );

            if (!empty($result['format_errors'])) {
                $formatErrorLines[] = [
                    'raw_line' => $line,
                    'errors'   => $result['format_errors'],
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
            ? 'Are you sure the data is correct? All the students will be marked no response since all the fields are blank.'
            : null;

        if (!empty($formatErrorLines) || !empty($incompleteRecords) || !empty($unmatchedLines) || !empty($missingStudents) || $allNoResponseWarning) {
            $validationErrors = [
                'unmatched_lines'    => $unmatchedLines,
                'missing_students'   => $missingStudents,
                'incomplete_records' => $incompleteRecords,
                'format_errors'      => $formatErrorLines,
            ];

            if ($allNoResponseWarning) {
                $validationErrors['all_no_response_warning'] = $allNoResponseWarning;
            }

            return response()->json([
                'message'            => 'Attendance text contains errors. Please correct them before saving the draft.',
                'metadata'           => $metadata,
                'errors'             => $validationErrors,
                'unmatched_lines'    => $unmatchedLines,
                'missing_students'   => $missingStudents,
                'incomplete_records' => $incompleteRecords,
                'format_errors'      => $formatErrorLines,
                'all_no_response_warning' => $allNoResponseWarning,
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

    public function parseBatchText(string $rawText, string $program, int $yearLevel): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawText));
        $students = [];
        $current = null;
        $slotType = null;

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            if (preg_match('/^(gathering|prayer meeting|worship service|thanksgiving)\s*[:\-]/i', $line)) continue;
            if (str_contains($line, '---') || str_contains($line, '[')) continue;

            if (preg_match('/^\d+[.)]\s*(.+)$/', $line, $m)) {
                if ($current) $students[] = $current;
                $current = ['name' => trim($m[1]), 'raw_line' => $line, 'slots' => ['pm' => null, 'ws' => null, 'tg' => null]];
                $slotType = null;
                continue;
            }

            if (!$current) continue;

            if (preg_match('/^(PM|WS|TG)[:\s]\s*(.*)$/i', $line, $m)) {
                $slotType = strtolower($m[1]);
                $rest = trim($m[2]);
                $current['slots'][$slotType] = $rest !== '' ? $this->parseSlotLine($rest) : null;
                continue;
            }

            if ($slotType) {
                $current['slots'][$slotType] = $this->parseSlotLine($line);
                $slotType = null;
            }
        }
        if ($current) $students[] = $current;

        $result = ['pm' => [], 'ws' => [], 'tg' => []];
        $unmatchedLines = [];
        $incompleteRecords = [];
        $formatErrorLines = [];
        $matchedStudentIds = [];

        foreach ($students as $entry) {
            $student = $this->findMatchingStudent($program, $yearLevel, $entry['name']);

            if (!$student) {
                $unmatchedLines[] = [
                    'raw_line'       => $entry['raw_line'],
                    'extracted_name' => $entry['name'],
                ];
                continue;
            }

            $matchedStudentIds[] = $student->id;

            foreach (['pm', 'ws', 'tg'] as $type) {
                $slot = $entry['slots'][$type];
                if (!$slot) continue; // fully empty slot = intentional no_response, not an error

                $hasDay = !empty($slot['day']) && strtolower($slot['day']) !== 'day';
                $hasTime = !empty($slot['time']) && strtolower($slot['time']) !== 'time';
                $hasVenue = !empty($slot['venue']) && strtolower($slot['venue']) !== 'venue';

                if ($hasTime && !$this->isValidBatchFormat($slot['time'])) {
                    $formatErrorLines[] = [
                        'student_name' => $student->name,
                        'gathering_type' => $type,
                        'field'  => 'time',
                        'value'  => $slot['time'],
                        'reason' => 'Time must be in a valid format, e.g. "8:30 AM".',
                    ];
                }

                $anyFilled = $hasDay || $hasTime || $hasVenue;
                $allFilled = $hasDay && $hasTime && $hasVenue;

                if ($anyFilled && !$allFilled) {
                    $incompleteRecords[] = [
                        'student_id'     => $student->id,
                        'student_name'   => $student->name,
                        'gathering_type' => $type,
                        'missing'        => array_values(array_filter([
                            !$hasDay ? 'day' : null,
                            !$hasTime ? 'time' : null,
                            !$hasVenue ? 'venue' : null,
                        ])),
                    ];
                }

                $result[$type][] = [
                    'student_id'     => $student->id,
                    'student_name'   => $student->name,
                    'gathering_type' => $type,
                    'batch'          => $hasTime ? $this->formatBatchTime($slot['time']) : null,
                    'venue'          => $hasVenue ? $slot['venue'] : null,
                    'day'            => $this->resolveDateForWeekday($type, $hasDay ? $slot['day'] : null),
                    'status'         => $allFilled ? 'present' : 'no_response',
                    'is_manual'      => false,
                ];
            }
        }

        $missingStudents = [];
        foreach (['pm', 'ws', 'tg'] as $type) {
            $missingStudents[$type] = Student::where('program', $program)
                ->where('year_level', $yearLevel)
                ->whereNotIn('id', $matchedStudentIds)
                ->get(['id', 'name'])
                ->map(fn($s) => ['student_id' => $s->id, 'student_name' => $s->name])
                ->values()
                ->toArray();
        }

        return [
            'records'            => $result,
            'unmatched_lines'    => $unmatchedLines,
            'incomplete_records' => $incompleteRecords,
            'format_errors'      => $formatErrorLines,
            'missing_students'   => $missingStudents,
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'draft_id'                  => 'nullable|exists:attendance_drafts,id',
            'records'                  => 'required|array|min:1',
            'records.*.student_id'     => 'required|exists:students,id',
            'records.*.gathering_type' => 'required|in:pm,ws,tg,special',
            'records.*.day'            => 'required|date',
            'records.*.status'         => 'required|in:present,no_response,excused',
            'records.*.batch'          => 'nullable|string',
            'records.*.venue'          => 'nullable|string',
        ]);

        $gatheringTypes = collect($validated['records'])->pluck('gathering_type')->unique();
        foreach ($gatheringTypes as $type) {
            if ($this->parsingService->hasSubmittedThisCycle($request->user()->id, $type)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "You have already submitted attendance for {$type} this cycle.",
                ], 422);
            }
        }

        $studentIds = collect($validated['records'])->pluck('student_id')->unique();
        $accessibleStudentCount = Student::whereKey($studentIds)
            ->accessibleTo($request->user())
            ->count();

        if ($accessibleStudentCount !== $studentIds->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to submit attendance for one or more students.',
            ], 403);
        }

        $submittedBy = $request->user()->id;
        $duplicateErrors = [];
        $recordsToInsert = [];

        // --- Step 1: Pre-validate duplicates before touching DB ---
        foreach ($validated['records'] as $index => $recordData) {

            // Check if attendance already exists in database with proper values
            $exists = AttendanceRecord::where('student_id', $recordData['student_id'])
                ->where('gathering_type', $recordData['gathering_type'])
                ->where('day', $recordData['day'])
                ->exists();

            if ($exists) {
                $duplicateErrors[] = [
                    'index'          => $index,
                    'student_id'     => $recordData['student_id'],
                    'gathering_type' => $recordData['gathering_type'],
                    'day'            => $recordData['day'],
                    'reason'         => 'Attendance record already submitted for this student and gathering date.',
                ];
            } else {
                $recordsToInsert[] = $recordData;
            }
        }

        // --- Step 2: Reject batch if any duplicate constraint fails ---
        if (!empty($duplicateErrors)) {
            return response()->json([
                'status'             => 'error',
                'message'            => 'Submission rejected. One or more attendance records already exist in the system.',
                'duplicates_count'   => count($duplicateErrors),
                'duplicate_records'  => $duplicateErrors,
            ], 422);
        }

        // --- Step 3: Atomic Bulk Insert within DB Transaction ---
        $savedRecords = DB::transaction(function () use ($recordsToInsert, $submittedBy, $validated, $request) {
    $records = [];
    
    // Generate a unique batch_id for this entire submission payload
    $batchId = (string) \Illuminate\Support\Str::uuid();
        foreach ($recordsToInsert as $recordData) {
            $formattedBatch = $this->parsingService->formatBatchTime($recordData['batch'] ?? null);

            $records[] = AttendanceRecord::create([
                'batch_id'          => $batchId, // <--- INSERTED HERE
                'student_id'        => $recordData['student_id'],
                'gathering_type'    => $recordData['gathering_type'],
                'day'               => $recordData['day'],
                'batch'             => $formattedBatch,
                'venue'             => isset($recordData['venue']) ? trim($recordData['venue']) : null,
                'status'            => $recordData['status'],
                'validation_status' => 'unvalidated',
                'submitted_by'      => $submittedBy,
            ]);
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

            return $records;
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Attendance batch submitted successfully and flagged for validation.',
            'count'   => count($savedRecords),
            'records' => $savedRecords,
        ], 201);
    }

    /**
     * Program Head or CM Coordinator validates/approves individual attendance submission.
     */
    /**
     * Validate all records belonging to a specific gathering session.
     */
    public function validateRecord(Request $request)
    {
        $request->validate([
            'program'        => 'required|string',
            'year_level'     => 'required|integer',
            'gathering_type' => 'required|in:pm,ws,tg,special',
            'day'            => 'required|date',
            'status'         => 'required|in:validated,rejected',
        ]);

        $updatedCount = AttendanceRecord::whereHas('student', function ($query) use ($request) {
                $query->where('program', $request->program)
                    ->where('year_level', $request->year_level);
            })
            ->where('gathering_type', $request->gathering_type)
            ->where('day', $request->day)
            ->update([
                'validation_status' => $request->status,
                'validated_by'      => $request->user()->id,
                'updated_at'        => now(),
            ]);

        return response()->json([
            'message'       => "Session attendance updated to {$request->status} across {$updatedCount} records.",
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