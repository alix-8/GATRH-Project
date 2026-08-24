<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Services\AttendanceParsingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    protected AttendanceParsingService $parsingService;

    public function __construct(AttendanceParsingService $parsingService)
    {
        $this->parsingService = $parsingService;
    }

    public function parseText(Request $request)
    {
        $request->validate([
            'raw_text'   => 'required|string',
            'program'    => 'nullable|string',
            'year_level' => 'nullable|int',
        ]);

        $rawText = trim($request->raw_text);
        $lines = explode("\n", $rawText);

        $detectedGathering = 'pm';
        $detectedProgram   = $request->program ? trim($request->program) : null;
        $detectedYear      = $request->year_level;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/Gathering:\s*(Prayer Meeting|Worship Service|Thanksgiving|Special|PM|WS|TG)/i', $line, $m)) {
                $inputGathering = strtolower(trim($m[1]));
                $detectedGathering = match ($inputGathering) {
                    'prayer meeting', 'pm'  => 'pm',
                    'worship service', 'ws' => 'ws',
                    'thanksgiving', 'tg'    => 'tg',
                    default                 => 'special',
                };
            }

            if (!$detectedProgram && preg_match('/Program:\s*([A-Za-z0-9]+)/i', $line, $m)) {
                $detectedProgram = strtoupper(trim($m[1]));
            }

            if (!$detectedYear && preg_match('/Year\s*Level:\s*(\d+)/i', $line, $m)) {
                $detectedYear = (int) trim($m[1]);
            }
        }

        $metadata = [
            'gathering_type' => $detectedGathering,
            'program'        => $detectedProgram ?? $request->program,
            'year_level'     => $detectedYear ?? $request->year_level,
            'default_date'   => now()->toDateString(),
        ];

        $parsedSubmissions = [];
        $unmatchedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_contains($line, '---') || str_contains($line, '[')) {
                continue;
            }

            if (preg_match('/^\d+[\.\)\-]?\s*(.+)$/i', $line)) {
                $result = $this->parsingService->buildRecordFromLine(
                    $line,
                    $metadata['program'],
                    $metadata['year_level'],
                    $metadata['default_date'],
                    $metadata['gathering_type']
                );

                if ($result['matched']) {
                    $studentId = $result['record']['student_id'];
                    $parsedSubmissions[$studentId] = $result['record'];
                } elseif (!empty($result['unmatched_data'])) {
                    $unmatchedLines[] = $result['unmatched_data'];
                }
            }
        }

        $sectionData = $this->parsingService->buildFullSectionRecords(
            $metadata['program'],
            $metadata['year_level'],
            $parsedSubmissions,
            $metadata['default_date'],
            $metadata['gathering_type']
        );

        return response()->json([
            'metadata' => $metadata,
            'summary'  => [
                'gathering_type'    => strtoupper($metadata['gathering_type']),
                'total_students'    => $sectionData['total_students'],
                'present_count'     => count($parsedSubmissions),
                'unmatched_count'   => count($unmatchedLines),
                'no_response_count' => $sectionData['total_students'] - count($parsedSubmissions),
            ],
            'unmatched_lines' => $unmatchedLines,
            'records'         => $sectionData['records'],
        ]);
    }

    public function reparseSingleLine(Request $request)
    {
        $request->validate([
            'line_text'       => 'required|string',
            'program'         => 'required|string',
            'year_level'      => 'required|int',
            'gathering_type'  => 'nullable|string|in:pm,ws,tg,special',
            'current_records' => 'nullable|array',
        ]);

        $gatheringType = strtolower(trim($request->gathering_type ?? 'pm'));
        $defaultDate   = now()->toDateString();
        $program       = trim($request->program);
        $yearLevel     = $request->year_level;

        $result = $this->parsingService->buildRecordFromLine(
            $request->line_text,
            $program,
            $yearLevel,
            $defaultDate,
            $gatheringType
        );

        if (!$result['matched']) {
            return response()->json([
                'matched' => false,
                'message' => "No student found matching input in {$program} Year {$yearLevel}.",
                'record'  => null,
            ], 404);
        }

        $parsedSubmissions = [];

        if ($request->has('current_records') && is_array($request->current_records)) {
            foreach ($request->current_records as $rec) {
                if (isset($rec['student_id']) && isset($rec['status']) && $rec['status'] === 'present') {
                    $parsedSubmissions[$rec['student_id']] = $rec;
                }
            }
        }

        $parsedSubmissions[$result['record']['student_id']] = $result['record'];

        $sectionData = $this->parsingService->buildFullSectionRecords(
            $program,
            $yearLevel,
            $parsedSubmissions,
            $defaultDate,
            $gatheringType
        );

        return response()->json([
            'matched' => true,
            'message' => 'Student successfully identified and records refreshed.',
            'record'  => $result['record'],
            'records' => $sectionData['records'],
        ], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'records'                  => 'required|array|min:1',
            'records.*.student_id'     => 'required|exists:students,id',
            'records.*.gathering_type' => 'required|in:pm,ws,tg,special',
            'records.*.day'            => 'required|date',
            'records.*.status'         => 'required|in:present,no_response,excused',
            'records.*.batch'          => 'nullable|string',
            'records.*.venue'          => 'nullable|string',
        ]);

        $submittedBy = $request->user()->id;

        $savedRecords = DB::transaction(function () use ($request, $submittedBy) {
            $records = [];
            foreach ($request->records as $recordData) {
                $formattedBatch = $this->parsingService->formatBatchTime($recordData['batch'] ?? null);

                $records[] = AttendanceRecord::updateOrCreate(
                    [
                        'student_id'     => $recordData['student_id'],
                        'gathering_type' => $recordData['gathering_type'],
                        'day'            => $recordData['day'],
                    ],
                    [
                        'batch'             => $formattedBatch,
                        'venue'             => isset($recordData['venue']) ? trim($recordData['venue']) : null,
                        'status'            => $recordData['status'],
                        'validation_status' => 'unvalidated',
                        'submitted_by'      => $submittedBy,
                    ]
                );
            }
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
            'program' => 'required|string',
            'year_level' => 'required|integer',
            'gathering_type' => 'required|in:pm,ws,tg,special',
            'day' => 'required|date',
            'status' => 'required|in:validated,rejected',
            'remarks' => 'nullable|string|max:255',
        ]);

        $updatedCount = AttendanceRecord::whereHas('student', function ($query) use ($request) {
                $query->where('program', $request->program)
                    ->where('year_level', $request->year_level);
            })
            ->where('gathering_type', $request->gathering_type)
            ->where('day', $request->day)
            ->update([
                'validation_status' => $request->status,
                'validated_by' => $request->user()->id,
                'remarks' => $request->remarks ?? null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => "Session attendance updated to {$request->status} across {$updatedCount} records.",
            'updated_count' => $updatedCount,
        ], 200);
    }

    /**
     * Summary analytics for higher management (Coordinator / Chancellor).
     */
    public function summaryReport(Request $request)
    {
        $program = $request->query('program');

        $query = AttendanceRecord::query();

        if ($program) {
            $query->whereHas('student', function ($q) use ($program) {
                $q->where('program', $program);
            });
        }

        $totalRecords    = $query->count();
        $presentCount    = (clone $query)->where('status', 'present')->count();
        $noResponseCount = (clone $query)->where('status', 'no_response')->count();
        $excusedCount    = (clone $query)->where('status', 'excused')->count();

        return response()->json([
            'program_filter'    => $program ?? 'All Programs',
            'total_records'     => $totalRecords,
            'present_count'     => $presentCount,
            'no_response_count' => $noResponseCount,
            'excused_count'     => $excusedCount,
            'attendance_rate'   => $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 2) . '%' : '0%',
        ]);
    }
}