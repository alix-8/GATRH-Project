<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use App\Models\AttendanceRecord;

class AttendanceController extends Controller
{
    public function parseText(Request $request)
    {
        $request->validate([
            'raw_text'   => 'required|string',
            'program'    => 'required|string',
            'year_level' => 'required|string',
        ]);

        $rawText = $request->raw_text;
        $lines = explode("\n", $rawText);

        // 1.Header Detection
        $detectedGathering = 'pm'; // default fallback

        // Regex captures PM, WS, TG, Thanksgiving, Worship Service, Prayer Meeting, or Special
        if (preg_match('/Gathering:\s*(Prayer Meeting|Worship Service|Thanksgiving|Special|PM|WS|TG)/i', $rawText, $m)) {
            $inputGathering = strtolower(trim($m[1]));

            $detectedGathering = match ($inputGathering) {
                'prayer meeting', 'pm'      => 'pm',
                'worship service', 'ws'     => 'ws',
                'thanksgiving', 'tg'        => 'tg',
                default                     => 'special',
            };
        }

        $metadata = [
            'gathering_type' => $detectedGathering,
            'program'        => $request->program,
            'year_level'     => $request->year_level,
        ];

        // 2. Parse Student Details from the Numbered List
        $parsedSubmissions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_contains($line, '---') || str_contains($line, '[')) {
                continue;
            }

            // Line Format: 1. Name | Time | Batch | Venue | Date
            if (preg_match('/^\d+[\.\)\-]?\s*(.+)$/i', $line, $matches)) {
                $content = $matches[1];
                $parts = array_map('trim', preg_split('/[\|\-,]/', $content));

                $studentName = $parts[0] ?? null;
                $time        = $parts[1] ?? null;
                $batch       = $parts[2] ?? null;
                $venue       = $parts[3] ?? null;
                $date        = $parts[4] ?? null;

                if ($studentName) {
                    $student = Student::where('program', $request->program)
                        ->where('year_level', $request->year_level)
                        ->where('name', 'LIKE', "%{$studentName}%")
                        ->first();

                    if ($student) {
                        $parsedSubmissions[$student->id] = [
                            'student_id'     => $student->id,
                            'student_name'   => $student->name,
                            'time'           => $time,
                            'batch'          => $batch,
                            'venue'          => $venue,
                            'day'            => $date,
                            'gathering_type' => $metadata['gathering_type'],
                            'status'         => 'present',
                        ];
                    }
                }
            }
        }

        // 3. Compare Masterlist & Flag Missing Students
        $allSectionStudents = Student::where('program', $request->program)
            ->where('year_level', $request->year_level)
            ->get();

        $finalAttendanceList = [];

        foreach ($allSectionStudents as $student) {
            if (isset($parsedSubmissions[$student->id])) {
                $finalAttendanceList[] = $parsedSubmissions[$student->id];
            } else {
                $finalAttendanceList[] = [
                    'student_id'     => $student->id,
                    'student_name'   => $student->name,
                    'time'           => null,
                    'batch'          => null,
                    'venue'          => null,
                    'day'            => null,
                    'gathering_type' => $metadata['gathering_type'],
                    'status'         => 'no_response',
                ];
            }
        }

        return response()->json([
            'metadata' => $metadata,
            'summary'  => [
                'gathering_type'    => strtoupper($metadata['gathering_type']),
                'total_students'    => $allSectionStudents->count(),
                'present_count'     => count($parsedSubmissions),
                'no_response_count' => $allSectionStudents->count() - count($parsedSubmissions),
            ],
            'records'  => $finalAttendanceList,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'records'                  => 'required|array|min:1',
            'records.*.student_id'     => 'required|exists:students,id',
            'records.*.gathering_type' => 'required|in:pm,ws,tg,special',
            'records.*.day'            => 'required|date',
            'records.*.status'         => 'required|in:present,no_response,excused',
            'records.*.time'           => 'nullable|string',
            'records.*.batch'          => 'nullable|string',
            'records.*.venue'          => 'nullable|string',
        ]);

        $submittedBy = $request->user()->id; // Logged-in Secretary ID
        $savedRecords = [];

        foreach ($request->records as $recordData) {
            $record = AttendanceRecord::updateOrCreate(
                [
                    'student_id'     => $recordData['student_id'],
                    'gathering_type' => $recordData['gathering_type'],
                    'day'            => $recordData['day'],
                ],
                [
                    'time'              => $recordData['time'] ?? null,
                    'batch'             => $recordData['batch'] ?? null,
                    'venue'             => $recordData['venue'] ?? null,
                    'status'            => $recordData['status'],
                    'validation_status' => 'unvalidated', // Awaits Program Head review
                    'submitted_by'      => $submittedBy,
                ]
            );

            $savedRecords[] = $record;
        }

        return response()->json([
            'message' => 'Attendance batch submitted successfully and pending validation.',
            'count'   => count($savedRecords),
        ], 201);
    }

    /* Program Head or CM Coordinator validates/approves attendance submission.
     */
    public function validateRecord(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:validated,rejected',
            'remarks' => 'nullable|string|max:255',
        ]);

        $record = AttendanceRecord::findOrFail($id);

        $record->update([
            'validation_status' => $request->status,
            'validated_by'      => $request->user()->id,
            'remarks'           => $request->remarks ?? null,
        ]);

        return response()->json([
            'message' => "Attendance record status updated to {$request->status}.",
            'record'  => $record,
        ]);
    }

    /*Summary analytics for higher management (Coordinator / Chancellor).*/
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