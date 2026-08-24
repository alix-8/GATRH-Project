<?php

namespace App\Services;

use App\Models\Student;

class AttendanceParsingService
{
    /**
     * Converts raw time strings (e.g., "10:30 AM", "2:15 PM") into MySQL TIME format (HH:MM:SS).
     */
    public function formatBatchTime(?string $batchTime): ?string
    {
        if (!$batchTime) {
            return null;
        }

        $timestamp = strtotime(trim($batchTime));

        if ($timestamp === false) {
            return null;
        }

        return date('H:i:s', $timestamp);
    }

    /**
     * Parses a single text line and matches it against student records.
     */
    public function buildRecordFromLine(string $line, string $program, int $yearLevel, string $defaultDate, string $gatheringType): array
    {
        $cleanLine = trim($line);

        // Strip leading numbers/bullets: "1. Name | Batch..." -> "Name | Batch..."
        if (preg_match('/^\d+[\.\)\-]?\s*(.+)$/i', $cleanLine, $matches)) {
            $cleanLine = trim($matches[1]);
        }

        $parts = array_map('trim', explode('|', $cleanLine));

        $studentName = isset($parts[0]) && $parts[0] !== '' ? trim($parts[0]) : null;
        $rawBatch    = isset($parts[1]) && $parts[1] !== '' ? trim($parts[1]) : null;
        $venue       = isset($parts[2]) && $parts[2] !== '' ? trim($parts[2]) : null;
        $date        = $defaultDate;

        if (isset($parts[3]) && preg_match('/\d{4}-\d{2}-\d{2}/', $parts[3])) {
            $date = trim($parts[3]);
        } elseif (isset($parts[2]) && preg_match('/\d{4}-\d{2}-\d{2}/', $parts[2])) {
            $date = trim($parts[2]);
            $venue = null;
        }

        if (!$studentName) {
            return ['matched' => false, 'unmatched_data' => null];
        }

        $student = Student::where('program', $program)
            ->where('year_level', $yearLevel)
            ->where('name', 'LIKE', "%{$studentName}%")
            ->first();

        $formattedBatch = $this->formatBatchTime($rawBatch);

        if (!$student) {
            return [
                'matched'        => false,
                'unmatched_data' => [
                    'raw_line'       => trim($line),
                    'extracted_name' => $studentName,
                    'batch'          => $formattedBatch,
                    'venue'          => $venue,
                    'day'            => $date,
                ],
            ];
        }

        return [
            'matched' => true,
            'record'  => [
                'student_id'     => $student->id,
                'student_name'   => $student->name,
                'gathering_type' => $gatheringType,
                'batch'          => $formattedBatch,
                'venue'          => $venue,
                'day'            => $date,
                'status'         => 'present',
                'is_manual'      => false,
            ],
        ];
    }

    /**
     * Rebuilds complete section masterlist combining parsed matches and missing students.
     */
    public function buildFullSectionRecords(string $program, int $yearLevel, array $parsedSubmissions, string $defaultDate, string $gatheringType): array
    {
        $allSectionStudents = Student::where('program', $program)
            ->where('year_level', $yearLevel)
            ->get();

        $finalAttendanceList = [];

        foreach ($allSectionStudents as $student) {
            if (isset($parsedSubmissions[$student->id])) {
                $finalAttendanceList[] = $parsedSubmissions[$student->id];
            } else {
                $finalAttendanceList[] = [
                    'student_id'     => $student->id,
                    'student_name'   => $student->name,
                    'batch'          => null,
                    'venue'          => null,
                    'day'            => $defaultDate,
                    'gathering_type' => $gatheringType,
                    'status'         => 'no_response',
                    'is_manual'      => false,
                ];
            }
        }

        return [
            'total_students' => $allSectionStudents->count(),
            'records'        => $finalAttendanceList,
        ];
    }
}