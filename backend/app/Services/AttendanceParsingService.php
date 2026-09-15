<?php

namespace App\Services;

use App\Models\Student;
use App\Models\AttendanceDraft;

class AttendanceParsingService
{
    /**
     * FOR SUBMIT: Converts raw time strings (e.g., "10:30 AM", "2:15 PM") into MySQL TIME format (HH:MM:SS).
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
     * Validates that a batch/time string matches an expected time format
     * (e.g. "8:30 AM", "08:30", "2:15 PM"). Returns true if valid or empty (optional field).
     */
    private function isValidBatchFormat(?string $rawBatch): bool
    {
        if (empty($rawBatch)) {
            return true; // empty is allowed — it's optional
        }

        // Accepts: "8:30 AM", "08:30 PM", "8:30", "08:30:00"
        return (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?\s*(AM|PM|am|pm)?$/', trim($rawBatch));
    }

    /**
     * Validates that a date string matches strict YYYY-MM-DD format.
        * Returns true if valid or empty (missing values are allowed during parsing).
     */
    private function isValidDateFormat(?string $rawDate): bool
    {
        if (empty($rawDate)) {
            return true; // empty is allowed — it remains missing until submission
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($rawDate))) {
            return false;
        }

        // Also confirm it's a real calendar date, not just the right shape (e.g. rejects 2026-13-40)
        [$year, $month, $day] = explode('-', trim($rawDate));
        return checkdate((int) $month, (int) $day, (int) $year);
    }

    /**
     * Validates that a date string is not in the future and not more than
     * $maxDaysInPast days old (defaults to 7 days = 1 week).
     */
    private function isDateWithinAllowedRange(?string $rawDate, int $maxDaysInPast = 7): bool
    {
        if (empty($rawDate)) {
            return true; // empty is missing — not this check's concern
        }

        try {
            $inputDate = \Carbon\Carbon::createFromFormat('Y-m-d', trim($rawDate))->startOfDay();
        } catch (\Exception $e) {
            return true; // malformed dates are caught by isValidDateFormat(), not here
        }

        $today = \Carbon\Carbon::today();
        $earliestAllowed = $today->copy()->subDays($maxDaysInPast);

        if ($inputDate->greaterThan($today)) {
            return false; // future date
        }

        if ($inputDate->lessThan($earliestAllowed)) {
            return false; // too far in the past
        }

        return true;
    }

    /**
     * Extracts header metadata (Gathering, Program, Year Level) from raw text,
     * preferring explicit overrides over values detected in the text.
     */
    public function extractHeaderMetadata(string $rawText, ?string $overrideProgram = null, ?int $overrideYearLevel = null, ?string $overrideGathering = null): array
    {
        $lines = explode("\n", $rawText);
        $detectedGathering = $overrideGathering ?: 'pm';
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

        if (preg_match('/^\d+[\.\)\-]?\s*(.+)$/i', $cleanLine, $matches)) {
            $cleanLine = trim($matches[1]);
        }

        $parts = array_map('trim', explode('|', $cleanLine));

        $studentName = isset($parts[0]) && $parts[0] !== '' ? trim($parts[0]) : null;
        $rawBatch    = isset($parts[1]) && $parts[1] !== '' ? trim($parts[1]) : null;
        $venue       = isset($parts[2]) && $parts[2] !== '' ? trim($parts[2]) : null;
        $rawDate     = null;
        $date        = null;

        if (isset($parts[3]) && $parts[3] !== '') {
            $rawDate = trim($parts[3]);
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $rawDate)) {
                $date = $rawDate;
            }
        } elseif (isset($parts[2]) && preg_match('/\d{4}-\d{2}-\d{2}/', $parts[2])) {
            $date = trim($parts[2]);
            $venue = null;
        }

        return [
            'student_name' => $studentName,
            'raw_batch'    => $rawBatch,
            'venue'        => $venue,
            'date'         => $date,
            'raw_date'     => $rawDate,
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
    private function buildUnmatchedRecord(string $rawLine, ?string $studentName, ?string $formattedBatch, ?string $venue, ?string $date): array
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
    private function buildMatchedRecord(Student $student, ?string $formattedBatch, ?string $venue, ?string $date, string $gatheringType): array
    {
        $hasAttendanceDetails = !empty($formattedBatch) && !empty($venue) && !empty($date);

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
            return ['matched' => false, 'unmatched_data' => null, 'format_errors' => []];
        }

        $formatErrors = [];

        if (!$this->isValidBatchFormat($parts['raw_batch'])) {
            $formatErrors[] = [
                'field'  => 'batch',
                'value'  => $parts['raw_batch'],
                'reason' => 'Batch time must be in a valid time format, e.g. "8:30 AM".',
            ];
        }

        if (!$this->isValidDateFormat($parts['raw_date'])) {
            $formatErrors[] = [
                'field'  => 'day',
                'value'  => $parts['raw_date'],
                'reason' => 'Date must be in YYYY-MM-DD format, e.g. "2026-08-28".',
            ];
        } elseif (!$this->isDateWithinAllowedRange($parts['raw_date'])) {
            // Only check the range if the format was already valid — no point
            // flagging a range issue on a date that's malformed anyway
            $formatErrors[] = [
                'field'  => 'day',
                'value'  => $parts['raw_date'],
                'reason' => 'Date must not be in the future and must be within the last 7 days.',
            ];
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
                'format_errors' => $formatErrors,
            ];
        }

        return [
            'matched'       => true,
            'record'        => $this->buildMatchedRecord($student, $formattedBatch, $parts['venue'], $parts['date'], $gatheringType),
            'format_errors' => $formatErrors,
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

    public function saveDraft(array $data, int $userId): AttendanceDraft
    {
        $attributes = ['user_id' => $userId];
        
        if (!empty($data['draft_id'])) {
            $attributes['id'] = $data['draft_id'];
        }

        return AttendanceDraft::updateOrCreate(
            $attributes,
            [
                'raw_text'       => $data['raw_text'],
                'parsed_data'    => $data['parsed_data'],
                'total_detected' => $data['total_detected'],
                'matched_count'  => $data['matched_count'],
                'status'         => 'draft',
            ]
        );
    }
}