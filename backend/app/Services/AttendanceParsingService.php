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
     * Extracts header metadata (Gathering, Program, Year Level) from raw text,
     * preferring explicit overrides over values detected in the text.
     */
    public function extractHeaderMetadata(string $rawText, ?string $overrideProgram = null, ?int $overrideYearLevel = null): array
    {
        $lines = explode("\n", $rawText);
        $detectedGathering = 'pm';
        $detectedProgram = $overrideProgram ? trim($overrideProgram) : null;
        $detectedYear = $overrideYearLevel;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/Gathering:\s*(Prayer Meeting|Worship Service|Thanksgiving|Special|PM|WS|TG)/i', $line, $m)) {
                $detectedGathering = match (strtolower(trim($m[1]))) {
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

        return [
            'gathering_type' => $detectedGathering,
            'program'        => $detectedProgram,
            'year_level'     => $detectedYear,
            'default_date'   => now()->toDateString(),
        ];
    }

    /**
     * Extracts name/batch/venue/date parts from a single raw line.
     */
    private function extractLineParts(string $line, string $defaultDate): array
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

        return [
            'student_name' => $studentName,
            'raw_batch'    => $rawBatch,
            'venue'        => $venue,
            'date'         => $date,
        ];
    }

    /**
     * Finds a student matching the given program, year level, and (fuzzy) name.
     */
    private function findMatchingStudent(string $program, int $yearLevel, string $studentName): ?Student
    {
        return Student::where('program', $program)
            ->where('year_level', $yearLevel)
            ->where('name', 'LIKE', "%{$studentName}%")
            ->first();
    }

    /**
     * Finds students belonging to the section who were not mentioned at all in the parsed submissions.
     */
    public function findMissingStudents(string $program, int $yearLevel, array $parsedSubmissions): array
    {
        return Student::where('program', $program)
            ->where('year_level', $yearLevel)
            ->whereNotIn('id', array_keys($parsedSubmissions))
            ->get(['id', 'name'])
            ->map(fn($s) => ['student_id' => $s->id, 'student_name' => $s->name])
            ->values()
            ->toArray();
    }

    /**
     * Builds the "unmatched" record shape for a line that couldn't be matched to a student.
     */
    private function buildUnmatchedRecord(string $rawLine, ?string $studentName, ?string $formattedBatch, ?string $venue, string $date): array
    {
        return [
            'raw_line'       => trim($rawLine),
            'extracted_name' => $studentName,
            'batch'          => $formattedBatch,
            'venue'          => $venue,
            'day'            => $date,
        ];
    }

    /**
     * Builds the "matched" record shape for a line successfully linked to a student.
     */
    private function buildMatchedRecord(Student $student, ?string $formattedBatch, ?string $venue, string $date, string $gatheringType): array
    {
        $hasAttendanceDetails = !empty($formattedBatch) || !empty($venue);

        return [
            'student_id'     => $student->id,
            'student_name'   => $student->name,
            'gathering_type' => $gatheringType,
            'batch'          => $formattedBatch,
            'venue'          => $venue,
            'day'            => $date,
            'status'         => $hasAttendanceDetails ? 'present' : 'no_response',
            'is_manual'      => false,
        ];
    }

    /**
     * Parses a single text line and matches it against student records.
     */
    public function buildRecordFromLine(string $line, string $program, int $yearLevel, string $defaultDate, string $gatheringType): array
    {
        $parts = $this->extractLineParts($line, $defaultDate);

        if (!$parts['student_name']) {
            return ['matched' => false, 'unmatched_data' => null];
        }

        $formattedBatch = $this->formatBatchTime($parts['raw_batch']);
        $student = $this->findMatchingStudent($program, $yearLevel, $parts['student_name']);

        if (!$student) {
            return [
                'matched'        => false,
                'unmatched_data' => $this->buildUnmatchedRecord(
                    $line,
                    $parts['student_name'],
                    $formattedBatch,
                    $parts['venue'],
                    $parts['date']
                ),
            ];
        }

        return [
            'matched' => true,
            'record'  => $this->buildMatchedRecord($student, $formattedBatch, $parts['venue'], $parts['date'], $gatheringType),
        ];
    }

    // /**
    //  * Shared line-by-line parser: splits raw text into candidate student lines,
    //  * skips headers/labels/blank lines, and matches each against a student record.
    //  */
    // private function parseLines(string $rawText, string $program, int $yearLevel, string $defaultDate, string $gatheringType): array
    // {
    //     $lines = explode("\n", trim($rawText));
    //     $matchedRecords = [];
    //     $unmatchedLines = [];

    //     foreach ($lines as $line) {
    //         $line = trim($line);

    //         if (empty($line) || str_contains($line, '---') || str_contains($line, '[')) {
    //             continue;
    //         }

    //         if (!preg_match('/^\d+[\.\)\-]?\s*(.+)$/i', $line)) {
    //             continue;
    //         }

    //         $result = $this->buildRecordFromLine($line, $program, $yearLevel, $defaultDate, $gatheringType);

    //         if ($result['matched']) {
    //             $matchedRecords[$result['record']['student_id']] = $result['record'];
    //         } elseif ($result['unmatched_data']) {
    //             $unmatchedLines[] = $result['unmatched_data'];
    //         }
    //     }

    //     return [
    //         'matched_records' => $matchedRecords,
    //         'unmatched'       => $unmatchedLines,
    //     ];
    // }

    // /**
    //  * Reparses delta text lines and patches over existing staged records
    //  * (used when a user edits/adds a few lines instead of resubmitting the whole form).
    //  */
    // public function reparseLines(string $rawText, string $program, int $yearLevel, string $defaultDate, string $gatheringType, array $existingStaged = []): array
    // {
    //     $result = $this->parseLines($rawText, $program, $yearLevel, $defaultDate, $gatheringType);

    //     foreach ($result['matched_records'] as $studentId => $record) {
    //         $existingStaged[$studentId] = $record;
    //     }

    //     return [
    //         'matched_records' => $result['matched_records'],
    //         'unmatched'       => $result['unmatched'],
    //         'staged_records'  => $existingStaged,
    //     ];
    // }

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