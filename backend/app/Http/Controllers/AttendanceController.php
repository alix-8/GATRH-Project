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
     * Generate a pre-filled GATRH text template with student names sorted alphabetically.
     */
    public function generateTemplate(Request $request)
    {
        $request->validate([
            'gathering_type' => 'nullable|string|in:pm,ws,tg,special',
        ]);

        $user = $request->user();
        $program = strtoupper((string) $user->program);
        $yearLevel = (int) $user->year_level;

        if (!$program || !$yearLevel) {
            return response()->json([
                'message' => 'Your account is not assigned to a program and year level.',
            ], 422);
        }
        $gatheringType = strtoupper(trim($request->gathering_type ?? 'PM'));

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
        $template .= "Program: {$program}\n";
        $template .= "Year Level: {$yearLevel}\n\n";
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
            $yearLevel
        );

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
            'gathering_id'   => $request->gathering_id ?? null,
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

    // public function reparseLines(Request $request){
    //     //
    // }

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

            foreach ($recordsToInsert as $recordData) {
                $formattedBatch = $this->parsingService->formatBatchTime($recordData['batch'] ?? null);

                $records[] = AttendanceRecord::create([
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