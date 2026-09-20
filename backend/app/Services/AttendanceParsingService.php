<?php

namespace App\Services;

use App\Models\Student;
use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Support\GatheringCycle;

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



    public function hasSubmittedThisCycle(int $userId, string $gatheringType): bool
    {
        $cycleStart = GatheringCycle::currentCycleStart($gatheringType);

        return AttendanceRecord::where('submitted_by', $userId)
            ->where('gathering_type', $gatheringType)
            ->whereDate('day', '>=', $cycleStart)
            ->exists();
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
     * Extracts metadata used to parse the attendance form.
     */
    public function extractHeaderMetadata(string $rawText, ?string $overrideProgram = null, ?int $overrideYearLevel = null, ?string $overrideGathering = null): array
    {
        $detectedGathering = $this->detectGatheringType($rawText);
        $detectedProgram = $overrideProgram ? trim($overrideProgram) : null;
        $detectedYear = $overrideYearLevel;

        return [
            'gathering_type' => $detectedGathering ?: ($overrideGathering ?: 'pm'),
            'program'        => $detectedProgram,
            'year_level'     => $detectedYear,
            'default_date'   => now()->toDateString(),
        ];
    }

    public function generateWeeklyTemplate(string $program, int $yearLevel): array
    {
        $students = Student::where('program', $program)
            ->where('year_level', $yearLevel)
            ->orderBy('name', 'asc')
            ->get();

        if ($students->isEmpty()) {
            return ['count' => 0, 'template' => null];
        }

        // $pmLabel = GatheringCycle::windowLabel('pm');
        // $wsLabel = GatheringCycle::windowLabel('ws');
        // $tgLabel = GatheringCycle::windowLabel('tg');

        $template = "--- GATRH ATTENDANCE FORM ---\n";
        $template .= "Gathering: WEEKLY REPORTING\n";
        $template .= "Prayer Meeting: \n";
        $template .= "Worship Service: \n";
        $template .= "Thanksgiving: \n";
        $template .= "\n";
        $template .= "[FILL OUT YOUR DETAILS BELOW]\n";

        foreach ($students as $index => $student) {
            $number = $index + 1;
            $template .= "{$number}. {$student->name}\n";
            $template .= "PM: Day| Time | Venue\n";
            $template .= "WS: Day| Time | Venue\n";
            $template .= "TG: Day| Time | Venue\n";
            $template .= "\n";
        }

        return ['count' => $students->count(), 'template' => rtrim($template) . "\n"];
    }


    /**
     * Reads the gathering value from the text header and normalizes it to its code.
     */
    private function detectGatheringType(string $rawText): ?string
    {
        if (!preg_match('/^\s*gathering\s*:\s*(.+?)\s*$/im', $rawText, $matches)) {
            return null;
        }

        return match (strtolower(trim($matches[1]))) {
            'pm', 'prayer meeting' => 'pm',
            'ws', 'worship service' => 'ws',
            'tg', 'thanksgiving' => 'tg',
            'special', 'special gathering' => 'special',
            default => strtolower(trim($matches[1])),
        };
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
            return [
                'matched'        => false,
                'unmatched_data' => null,
                'format_errors'  => [[
                    'field'  => 'name',
                    'value'  => null,
                    'reason' => 'Student name and attendance details cannot all be empty.',
                ]],
            ];
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

    private const WEEKDAY_MAP = [
        'sun' => 0, 'sunday' => 0, 'mon' => 1, 'monday' => 1,
        'tue' => 2, 'tues' => 2, 'tuesday' => 2, 'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thurs' => 4, 'thursday' => 4, 'fri' => 5, 'friday' => 5,
        'sat' => 6, 'saturday' => 6,
    ];

    public function parseBatchText(string $rawText, string $program, int $yearLevel): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawText));
        $students = [];
        $current = null;
        $slotType = null;

        $startIndex = 0;
        if (preg_match('/^\s*(.+?)\s*[-–:]\s*year\s*\d+/i', trim($lines[0] ?? ''))) {
            $startIndex = 1; // skip header line like "BSIS - Year 3"
        }

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;

            if (preg_match('/^\d+[.)]\s*(.+)$/', $line, $m)) {
                if ($current) $students[] = $current;
                $current = ['name' => trim($m[1]), 'slots' => ['pm' => null, 'ws' => null, 'tg' => null]];
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
        $unmatched = [];

        foreach ($students as $entry) {
            $student = $this->findMatchingStudent($program, $yearLevel, $entry['name']);
            if (!$student) {
                $unmatched[] = $entry['name'];
                continue;
            }

            foreach (['pm', 'ws', 'tg'] as $type) {
                $slot = $entry['slots'][$type];
                if (!$slot) continue;

                $result[$type][] = [
                    'student_id'     => $student->id,
                    'student_name'   => $student->name,
                    'gathering_type' => $type,
                    'batch'          => $this->formatBatchTime($slot['time'] ?? null),
                    'venue'          => $slot['venue'] ?? null,
                    'day'            => $this->resolveDateForWeekday($type, $slot['day'] ?? null),
                    'status'         => 'present',
                    'is_manual'      => false,
                ];
            }
        }

        return ['records' => $result, 'unmatched' => $unmatched];
    }

    private function parseSlotLine(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), 3);
        return ['day' => $parts[0] ?? null, 'time' => $parts[1] ?? null, 'venue' => $parts[2] ?? null];
    }

    private function resolveDateForWeekday(string $gatheringType, ?string $dayName): string
    {
        $cycleStart = \App\Support\GatheringCycle::currentCycleStart($gatheringType);
        $target = self::WEEKDAY_MAP[strtolower(trim((string) $dayName))] ?? null;

        if ($target === null) return $cycleStart->toDateString();

        for ($offset = 0; $offset < 7; $offset++) {
            $candidate = $cycleStart->copy()->addDays($offset);
            if ($candidate->dayOfWeek === $target) return $candidate->toDateString();
        }

        return $cycleStart->toDateString();
    }


    public function buildFullSectionRecords(string $program, int $yearLevel, array $parsedSubmissions, string $defaultDate, string $gatheringType): array
    {
        $allSectionStudents = Student::where('program', $program)
            ->where('year_level', $yearLevel)
            ->get();

        $finalAttendanceList = [];

        foreach ($allSectionStudents as $student) {
            if (isset($parsedSubmissions[$student->id])) {
                $finalAttendanceList[] = array_merge($parsedSubmissions[$student->id], [
                    'program'    => $program,
                    'year_level' => $yearLevel,
                ]);
            } else {
                $finalAttendanceList[] = [
                    'student_id'     => $student->id,
                    'student_name'   => $student->name,
                    'program'        => $program,
                    'year_level'     => $yearLevel,
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
        } else {
            $attributes['gathering_type'] = $data['gathering_type'] ?? null;
            $attributes['day'] = $data['day'] ?? null;
        }

        return AttendanceDraft::updateOrCreate(
            $attributes,
            [
                'gathering_type' => $data['gathering_type'] ?? null,
                'day'            => $data['day'] ?? null,
                'raw_text'       => $data['raw_text'],
                'parsed_data'    => $data['parsed_data'],
                'total_detected' => $data['total_detected'],
                'matched_count'  => $data['matched_count'],
                'status'         => 'draft',
            ]
        );
    }
}