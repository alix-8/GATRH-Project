<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function parseText(Request $request)
    {
        $request->validate([
            'raw_text'   => 'required|string',
            'program'    => 'required|string',     // e.g., 'BSIT'
            'year_level' => 'required|string',  // e.g., '3'
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
}