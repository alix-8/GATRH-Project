<?php

namespace App\Services\Attendance;

use App\Models\Student;
use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Support\GatheringCycle;

class AttendanceParsingService
{
    /**
     * FOR SUBMIT: Converts raw time strings (e.g., "8AM", "10:30 AM", "2:15 PM") into MySQL TIME format (HH:MM:SS).
     */
    public function formatBatchTime(?string $batchTime): ?string
    {
        if (!$batchTime) {
            return null;
        }

        $batchTime = trim($batchTime);
        if (preg_match('/^(\d{1,2})\s*(AM|PM)$/i', $batchTime, $matches)) {
            $batchTime = $matches[1] . ':00 ' . strtoupper($matches[2]);
        }

        $timestamp = strtotime($batchTime);

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
     * (e.g. "8AM", "8:30 AM", "08:30", "2:15 PM"). Returns true if valid or empty (optional field).
     */
    private function isValidBatchFormat(?string $rawBatch): bool
    {
        if (empty($rawBatch)) {
            return true; // empty is allowed — it's optional
        }

        // Accepts compact meridiem times as well as the existing colon-separated formats.
        $time = trim($rawBatch);
        return (bool) (
            preg_match('/^(?:[1-9]|1[0-2])\s*(?:AM|PM)$/i', $time)
            || preg_match('/^\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?$/i', $time)
        );
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
    public function extractHeaderMetadata(string $rawText, ?string $overrideProgram = null, ?int $overrideYearLevel = null, ?string $overrideGathering = null, $cycle = null): array
    {
        $detectedGathering = $this->detectGatheringType($rawText);
        $detectedProgram = $overrideProgram ? trim($overrideProgram) : null;
        $detectedYear = $overrideYearLevel;
        $gatheringType = $detectedGathering ?: ($overrideGathering ?: 'pm');
        $range = $this->getApplicableWeekRange($gatheringType, $cycle, $rawText);

        return [
            'gathering_type' => $gatheringType,
            'program'        => $detectedProgram,
            'year_level'     => $detectedYear,
            'default_date'   => $range['start']->toDateString(),
        ];
    }

    public function generateWeeklyTemplate(string $program, int $yearLevel, ?array $periodRanges = null): array
    {
        $students = Student::where('program', $program)
            ->active()
            ->where('year_level', $yearLevel)
            ->orderBy('name', 'asc')
            ->get();

        if ($students->isEmpty()) {
            return ['count' => 0, 'template' => null];
        }

        $ranges = $periodRanges ?? GatheringCycle::getImportPeriodRanges('current');
        $label = fn (string $type): string => GatheringCycle::formatRangeLabel($ranges[$type]['start'], $ranges[$type]['end']);
        $pmLabel = $label('pm');
        $wsLabel = $label('ws');
        $tgLabel = $label('tg');

        $template = "--- GATRH ATTENDANCE FORM ---\n";
        $template .= "Gathering: WEEKLY REPORTING\n";
        $template .= "Prayer Meeting - {$pmLabel}\n";
        $template .= "Worship Service - {$wsLabel}\n";
        $template .= "Thanksgiving - {$tgLabel}\n";
        $template .= "\n";
        $template .= "[FILL OUT YOUR DETAILS BELOW]\n";
        $template .= "Format: Day (e.g., Monday, Mon) | Time | Venue\n\n";

        foreach ($students as $index => $student) {
            $number = $index + 1;
            $template .= "{$number}. {$student->name}\n";
            $template .= "PM: | | \n";
            $template .= "WS: | | \n";
            $template .= "TG: | | \n";
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

        $gathering = strtolower(trim($matches[1]));
        if (preg_match('/^(prayer meeting|worship service|thanksgiving)\b/i', $gathering, $labelMatch)) {
            $gathering = strtolower($labelMatch[1]);
        }
        return match ($gathering) {
            'pm', 'prayer meeting' => 'pm',
            'ws', 'worship service' => 'ws',
            'tg', 'thanksgiving' => 'tg',
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
        } elseif (isset($parts[2]) && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2]) || isset(self::WEEKDAY_MAP[strtolower(trim($parts[2]))]))) {
            $rawDate = trim($parts[2]);
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
            ->active()
            ->where('year_level', $yearLevel)
            ->where('name', 'LIKE', "%{$studentName}%")
            ->first();
    }

    /** Finds a matching active student outside the secretary's assigned course and year. */
    private function findMatchingStudentOutsideSection(string $program, int $yearLevel, string $studentName): ?Student
    {
        return Student::active()
            ->where('name', 'LIKE', "%{$studentName}%")
            ->where(function ($query) use ($program, $yearLevel) {
                $query->where('program', '!=', $program)
                    ->orWhere('year_level', '!=', $yearLevel);
            })
            ->first(['id', 'name', 'program', 'year_level']);
    }

    /** Finds an inactive student by the name supplied in an attendance import. */
    private function findMatchingInactiveStudent(string $program, int $yearLevel, string $studentName): ?Student
    {
        $matches = Student::where('is_active', false)
            ->where('name', 'LIKE', "%{$studentName}%")
            ->get(['id', 'name', 'program', 'year_level']);

        $sameSectionMatch = $matches->first(fn (Student $student) =>
            $student->program === $program && (int) $student->year_level === $yearLevel
        );

        return $sameSectionMatch ?? ($matches->count() === 1 ? $matches->first() : null);
    }

    /**
     * Finds students belonging to the section who were not mentioned at all in the parsed submissions.
     */
    public function findMissingStudents(string $program, int $yearLevel, array $parsedSubmissions): array
    {
        return Student::where('program', $program)
            ->active()
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
    public function buildRecordFromLine(string $line, string $program, int $yearLevel, string $defaultDate, string $gatheringType, $cycle = null, ?string $rawText = null): array
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

        if (mb_strlen((string) $parts['raw_batch']) > 10) {
            $formatErrors[] = [
                'field'  => 'batch',
                'value'  => $parts['raw_batch'],
                'reason' => 'Time must be 10 characters or fewer.',
            ];
        } elseif (!$this->isValidBatchFormat($parts['raw_batch'])) {
            $formatErrors[] = [
                'field'  => 'batch',
                'value'  => $parts['raw_batch'],
                'reason' => 'Batch time must be in a valid time format, e.g. "8AM" or "8:30 AM".',
            ];
        }

        if (mb_strlen((string) $parts['venue']) > 25) {
            $formatErrors[] = [
                'field'  => 'venue',
                'value'  => $parts['venue'],
                'reason' => 'Venue must be 25 characters or fewer.',
            ];
        }

        $hasAnyDetails = !empty($parts['raw_batch']) || !empty($parts['venue']) || !empty($parts['raw_date']);

        if (mb_strlen((string) $parts['raw_date']) > 10) {
            $formatErrors[] = [
                'field'  => 'day',
                'value'  => $parts['raw_date'],
                'reason' => 'Day must be 10 characters or fewer.',
            ];
        } elseif (!empty($parts['raw_date'])) {
            $range = $this->getApplicableWeekRange($gatheringType, $cycle, $rawText);
            $resolution = $this->resolveWeekdayToDate($parts['raw_date'], $range['start'], $range['end']);
            if ($resolution['success']) {
                $parts['date'] = $resolution['date'];
            } else {
                $formatErrors[] = [
                    'field'  => 'day',
                    'value'  => $parts['raw_date'],
                    'reason' => $resolution['error'],
                ];
            }
        } elseif ($hasAnyDetails) {
            $formatErrors[] = [
                'field'  => 'day',
                'value'  => null,
                'reason' => 'Weekday is missing. Please provide a weekday name (e.g., Monday or Mon).',
            ];
        }

        $formattedBatch = $this->formatBatchTime($parts['raw_batch']);
        $student = $this->findMatchingStudent($program, $yearLevel, $parts['student_name']);

        if (!$student) {
            $inactiveStudent = $this->findMatchingInactiveStudent($program, $yearLevel, $parts['student_name']);
            $outsideStudent = $this->findMatchingStudentOutsideSection($program, $yearLevel, $parts['student_name']);
            return [
                'matched'        => false,
                'inactive_student_data' => $inactiveStudent ? [
                    'extracted_name' => $parts['student_name'],
                    'student_name' => $inactiveStudent->name,
                    'program' => $inactiveStudent->program,
                    'year_level' => $inactiveStudent->year_level,
                ] : null,
                'out_of_course_data' => $outsideStudent ? [
                    'extracted_name' => $parts['student_name'],
                    'student_name' => $outsideStudent->name,
                    'program' => $outsideStudent->program,
                    'year_level' => $outsideStudent->year_level,
                ] : null,
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

    public const WEEKDAY_MAP = [
        'sun'       => \Carbon\Carbon::SUNDAY,
        'sunday'    => \Carbon\Carbon::SUNDAY,
        'mon'       => \Carbon\Carbon::MONDAY,
        'monday'    => \Carbon\Carbon::MONDAY,
        'tue'       => \Carbon\Carbon::TUESDAY,
        'tues'      => \Carbon\Carbon::TUESDAY,
        'tuesday'   => \Carbon\Carbon::TUESDAY,
        'wed'       => \Carbon\Carbon::WEDNESDAY,
        'wednesday' => \Carbon\Carbon::WEDNESDAY,
        'thu'       => \Carbon\Carbon::THURSDAY,
        'thurs'     => \Carbon\Carbon::THURSDAY,
        'thursday'  => \Carbon\Carbon::THURSDAY,
        'fri'       => \Carbon\Carbon::FRIDAY,
        'friday'    => \Carbon\Carbon::FRIDAY,
        'sat'       => \Carbon\Carbon::SATURDAY,
        'saturday'  => \Carbon\Carbon::SATURDAY,
    ];

    public function parseBatchText(string $rawText, string $program, int $yearLevel, $cycle = null): array
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

            if ($this->isUnnumberedStudentNameLine($line)) {
                if ($current) $students[] = $current;
                $current = ['name' => $line, 'raw_line' => $line, 'slots' => ['pm' => null, 'ws' => null, 'tg' => null]];
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
        $outOfCourseStudents = [];
        $inactiveStudents = [];
        $incompleteRecords = [];
        $formatErrorLines = [];
        $blankEntries = [];
        $matchedStudentIds = [];

        foreach ($students as $entry) {
            $student = $this->findMatchingStudent($program, $yearLevel, $entry['name']);

            if (!$student) {
                $inactiveStudent = $this->findMatchingInactiveStudent($program, $yearLevel, $entry['name']);
                $outsideStudent = $this->findMatchingStudentOutsideSection($program, $yearLevel, $entry['name']);
                if ($inactiveStudent) {
                    $inactiveStudents[] = [
                        'extracted_name' => $entry['name'],
                        'student_name' => $inactiveStudent->name,
                        'program' => $inactiveStudent->program,
                        'year_level' => $inactiveStudent->year_level,
                    ];
                } elseif ($outsideStudent) {
                    $outOfCourseStudents[] = [
                        'extracted_name' => $entry['name'],
                        'student_name' => $outsideStudent->name,
                        'program' => $outsideStudent->program,
                        'year_level' => $outsideStudent->year_level,
                    ];
                } else {
                    $unmatchedLines[] = [
                        'raw_line'       => $entry['raw_line'],
                        'extracted_name' => $entry['name'],
                    ];
                }
                continue;
            }

            $matchedStudentIds[] = $student->id;

            $hasAnySlotFilled = false;
            foreach (['pm', 'ws', 'tg'] as $type) {
                $slot = $entry['slots'][$type];
                if ($slot && (!empty($slot['day']) || !empty($slot['time']) || !empty($slot['venue']))) {
                    $hasAnySlotFilled = true;
                    break;
                }
            }

            if (!$hasAnySlotFilled) {
                $blankEntries[] = [
                    'student_id'   => $student->id,
                    'student_name' => $student->name,
                    'reason'       => 'All PM/WS/TG fields are blank for this student.',
                ];

                foreach (['pm', 'ws', 'tg'] as $type) {
                    $range = $this->getApplicableWeekRange($type, $cycle, $rawText);
                    if ($range['start']->greaterThan(\Carbon\Carbon::today())) {
                        continue;
                    }
                    $result[$type][] = [
                        'student_id'     => $student->id,
                        'student_name'   => $student->name,
                        'gathering_type' => $type,
                        'batch'          => null,
                        'venue'          => null,
                        'day'            => $range['start']->toDateString(),
                        'status'         => 'no_response',
                        'is_manual'      => false,
                    ];
                }

                continue;
            }

            foreach (['pm', 'ws', 'tg'] as $type) {
                $slot = $entry['slots'][$type];
                if (!$slot) continue;

                $hasDay = !empty($slot['day']);
                $hasTime = !empty($slot['time']);
                $hasVenue = !empty($slot['venue']);

                if ($hasTime && (mb_strlen((string) $slot['time']) > 10 || !$this->isValidBatchFormat($slot['time']))) {
                    $timeErr = [
                        'field'  => 'time',
                        'value'  => $slot['time'],
                        'reason' => mb_strlen((string) $slot['time']) > 10
                            ? 'Time must be 10 characters or fewer.'
                            : 'Time must be in a valid format, e.g. "8AM" or "8:30 AM".',
                    ];
                    $formatErrorLines[] = [
                        'student_name'   => $student->name,
                        'gathering_type' => $type,
                        'field'          => 'time',
                        'value'          => $slot['time'],
                        'reason'         => $timeErr['reason'],
                        'raw_line'       => "{$student->name} (" . strtoupper($type) . "): {$slot['time']}",
                        'errors'         => [$timeErr],
                    ];
                }

                if ($hasVenue && mb_strlen((string) $slot['venue']) > 25) {
                    $venueErr = [
                        'field' => 'venue',
                        'value' => $slot['venue'],
                        'reason' => 'Venue must be 25 characters or fewer.',
                    ];
                    $formatErrorLines[] = [
                        'student_name' => $student->name,
                        'gathering_type' => $type,
                        'field' => 'venue',
                        'value' => $slot['venue'],
                        'reason' => $venueErr['reason'],
                        'raw_line' => "{$student->name} (" . strtoupper($type) . "): {$slot['venue']}",
                        'errors' => [$venueErr],
                    ];
                }

                $range = $this->getApplicableWeekRange($type, $cycle, $rawText);
                $resolvedDay = null;

                if (!$hasDay && !$hasTime && !$hasVenue && $range['start']->greaterThan(\Carbon\Carbon::today())) {
                    continue;
                }

                if ($hasDay && mb_strlen((string) $slot['day']) > 10) {
                    $dayErr = [
                        'field' => 'day',
                        'value' => $slot['day'],
                        'reason' => 'Day must be 10 characters or fewer.',
                    ];
                    $formatErrorLines[] = [
                        'student_name' => $student->name,
                        'gathering_type' => $type,
                        'field' => 'day',
                        'value' => $slot['day'],
                        'reason' => $dayErr['reason'],
                        'raw_line' => "{$student->name} (" . strtoupper($type) . "): {$slot['day']}",
                        'errors' => [$dayErr],
                    ];
                } elseif ($hasDay) {
                    $resolution = $this->resolveWeekdayToDate($slot['day'], $range['start'], $range['end']);
                    if ($resolution['success']) {
                        $resolvedDay = $resolution['date'];
                    } else {
                        $dayErr = [
                            'field'  => 'day',
                            'value'  => $slot['day'],
                            'reason' => $resolution['error'],
                        ];
                        $formatErrorLines[] = [
                            'student_name'   => $student->name,
                            'gathering_type' => $type,
                            'field'          => 'day',
                            'value'          => $slot['day'],
                            'reason'         => $resolution['error'],
                            'raw_line'       => "{$student->name} (" . strtoupper($type) . "): {$slot['day']}",
                            'errors'         => [$dayErr],
                        ];
                    }
                } elseif ($hasTime || $hasVenue) {
                    $missingDayErr = [
                        'field'  => 'day',
                        'value'  => null,
                        'reason' => 'Weekday is missing. Please provide a weekday name (e.g., Monday or Mon).',
                    ];
                    $formatErrorLines[] = [
                        'student_name'   => $student->name,
                        'gathering_type' => $type,
                        'field'          => 'day',
                        'value'          => null,
                        'reason'         => $missingDayErr['reason'],
                        'raw_line'       => "{$student->name} (" . strtoupper($type) . ")",
                        'errors'         => [$missingDayErr],
                    ];
                }

                $anyFilled = $hasDay || $hasTime || $hasVenue;
                $allFilled = $hasDay && $hasTime && $hasVenue && $resolvedDay !== null;

                if ($anyFilled && (!$hasDay || !$hasTime || !$hasVenue)) {
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
                    'day'            => $resolvedDay,
                    'status'         => $allFilled ? 'present' : 'no_response',
                    'is_manual'      => false,
                ];
            }
        }

        $missingStudents = [];
        foreach (['pm', 'ws', 'tg'] as $type) {
            $missingStudents[$type] = Student::where('program', $program)
                ->active()
                ->where('year_level', $yearLevel)
                ->whereNotIn('id', $matchedStudentIds)
                ->get(['id', 'name'])
                ->map(fn($s) => ['student_id' => $s->id, 'student_name' => $s->name])
                ->values()
                ->toArray();
        }

        $totalSlotsFilled = collect($result['pm'])->count() + collect($result['ws'])->count() + collect($result['tg'])->count();

        $allNoResponseWarning = !empty($students)
            && empty($unmatchedLines)
            && $totalSlotsFilled === 0
            ? 'Are you sure the data is correct? All students will be marked no response since all the fields are blank.'
            : null;

        return [
            'records'                 => $result,
            'unmatched_lines'         => $unmatchedLines,
            'out_of_course_students' => $outOfCourseStudents,
            'inactive_students'       => $inactiveStudents,
            'incomplete_records'      => $incompleteRecords,
            'format_errors'           => $formatErrorLines,
            'blank_entries'           => $blankEntries,
            'missing_students'        => $missingStudents,
            'all_no_response_warning' => $allNoResponseWarning,
        ];
    }

    private function parseSlotLine(string $line): array
    {
        $parts = array_map('trim', explode('|', $line));
        $clean = fn ($v) => ($v !== '' && !preg_match('/^(day|time|venue)$/i', $v)) ? $v : null;

        return [
            'day'   => $clean($parts[0] ?? ''),
            'time'  => $clean($parts[1] ?? ''),
            'venue' => $clean(implode(' | ', array_slice($parts, 2))),
        ];
    }

    private function isUnnumberedStudentNameLine(string $line): bool
    {
        if ($line === ''
            || str_contains($line, '|')
            || str_contains($line, ':')
            || str_contains($line, '---')
            || str_contains($line, '[')
            || preg_match('/^(gathering|prayer meeting|worship service|thanksgiving|format|program|year|attendance|cycle|week range|date)\s*[:\-]/i', $line)) {
            return false;
        }

        return preg_match('/^[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N}\'’.,()& -]*$/u', $line) === 1;
    }

    public function resolveDateForWeekday(string $gatheringType, ?string $dayName, $cycle = null): ?string
    {
        $range = $this->getApplicableWeekRange($gatheringType, $cycle);
        $res = $this->resolveWeekdayToDate($dayName, $range['start'], $range['end']);
        return $res['success'] ? $res['date'] : null;
    }

    public function resolveWeekdayToDate(?string $weekday, \Carbon\Carbon $rangeStart, \Carbon\Carbon $rangeEnd): array
    {
        if ($weekday === null || trim($weekday) === '') {
            return [
                'success' => false,
                'date'    => null,
                'error'   => 'Weekday is missing. Please provide a weekday name (e.g., Monday or Mon).',
            ];
        }

        $clean = trim($weekday);
        $key = strtolower($clean);

        if (isset(self::WEEKDAY_MAP[$key])) {
            $targetDay = self::WEEKDAY_MAP[$key];
            $matches = [];
            $curr = $rangeStart->copy()->startOfDay();
            $end = $rangeEnd->copy()->startOfDay();

            if ($curr->greaterThan($end)) {
                return [
                    'success' => false,
                    'date'    => null,
                    'error'   => 'Configured week range start date is after end date.',
                ];
            }

            while ($curr->lessThanOrEqualTo($end)) {
                if ($curr->dayOfWeek === $targetDay) {
                    $matches[] = $curr->toDateString();
                }
                $curr->addDay();
            }

            if (count($matches) === 1) {
                if (\Carbon\Carbon::parse($matches[0])->isAfter(\Carbon\Carbon::today())) {
                    return [
                        'success' => false,
                        'date' => null,
                        'error' => "Attendance cannot be added for {$clean} ({$matches[0]}) before that date.",
                    ];
                }
                return [
                    'success' => true,
                    'date'    => $matches[0],
                    'error'   => null,
                ];
            }

            if (count($matches) === 0) {
                $startFormatted = $rangeStart->format('F j, Y');
                $endFormatted = $rangeEnd->format('F j, Y');
                return [
                    'success' => false,
                    'date'    => null,
                    'error'   => "Weekday \"{$clean}\" does not fall within the applicable week range ({$startFormatted} to {$endFormatted}).",
                ];
            }

            return [
                'success' => false,
                'date'    => null,
                'error'   => "Weekday \"{$clean}\" is ambiguous and matches multiple dates in the configured week range.",
            ];
        }

        // Also accept existing calendar date in YYYY-MM-DD format if valid and in range
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
            [$y, $m, $d] = explode('-', $clean);
            if (checkdate((int)$m, (int)$d, (int)$y)) {
                $dateObj = \Carbon\Carbon::createFromFormat('Y-m-d', $clean)->startOfDay();
                $start = $rangeStart->copy()->startOfDay();
                $end = $rangeEnd->copy()->startOfDay();

                if ($dateObj->greaterThanOrEqualTo($start) && $dateObj->lessThanOrEqualTo($end)) {
                    if ($dateObj->greaterThan(\Carbon\Carbon::today())) {
                        return [
                            'success' => false,
                            'date' => null,
                            'error' => "Attendance cannot be added for {$clean} before that date.",
                        ];
                    }
                    return [
                        'success' => true,
                        'date'    => $clean,
                        'error'   => null,
                    ];
                }

                $startFormatted = $rangeStart->format('F j, Y');
                $endFormatted = $rangeEnd->format('F j, Y');
                return [
                    'success' => false,
                    'date'    => null,
                    'error'   => "Date \"{$clean}\" does not fall within the applicable week range ({$startFormatted} to {$endFormatted}).",
                ];
            }
        }

        return [
            'success' => false,
            'date'    => null,
            'error'   => "Weekday \"{$clean}\" is invalid. Accepted values: Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, Sunday (or Mon, Tue, Wed, Thu, Fri, Sat, Sun).",
        ];
    }

    public function getApplicableWeekRange(string $gatheringType, $customCycle = null, ?string $rawText = null): array
    {
        $type = strtolower($gatheringType);

        if ($customCycle !== null) {
            if (is_array($customCycle)) {
                if (isset($customCycle[$type])) {
                    $norm = GatheringCycle::normalizeRange($customCycle[$type]);
                    if ($norm) return $norm;
                }
                $norm = GatheringCycle::normalizeRange($customCycle);
                if ($norm) return $norm;
            } elseif (is_string($customCycle)) {
                $norm = GatheringCycle::normalizeRange($customCycle);
                if ($norm) return $norm;
            }
        }

        if ($rawText !== null) {
            $extracted = $this->extractWeekRangeFromHeader($rawText, $type);
            if ($extracted) {
                return $extracted;
            }
        }

        return GatheringCycle::getWeekRange($type);
    }

    public function extractWeekRangeFromHeader(string $rawText, string $gatheringType): ?array
    {
        $type = strtolower($gatheringType);
        $typeLabels = [
            'pm' => ['prayer meeting', 'pm'],
            'ws' => ['worship service', 'ws'],
            'tg' => ['thanksgiving', 'tg'],
        ];

        $lines = preg_split('/\r\n|\r|\n/', trim($rawText));
        $generalRange = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, '---') || str_contains($line, '[')) {
                continue;
            }
            if (preg_match('/^\d+[.)\-]/', $line)) {
                break;
            }

            if (isset($typeLabels[$type])) {
                foreach ($typeLabels[$type] as $lbl) {
                    if (preg_match('/^' . preg_quote($lbl, '/') . '\s*[-:]\s*(.+)$/i', $line, $m)) {
                        $parsed = GatheringCycle::parseRangeString($m[1]);
                        if ($parsed) return $parsed;
                    }
                }
            }

            if (preg_match('/^(?:cycle|week\s*range|week|reporting\s*week|date)\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $parsed = GatheringCycle::parseRangeString($m[1]);
                if ($parsed) {
                    $generalRange = $parsed;
                }
            }

            if ($generalRange === null) {
                $parsed = GatheringCycle::parseRangeString($line);
                if ($parsed) {
                    $generalRange = $parsed;
                }
            }
        }

        return $generalRange;
    }


    public function buildFullSectionRecords(string $program, int $yearLevel, array $parsedSubmissions, string $defaultDate, string $gatheringType): array
    {
        $allSectionStudents = Student::where('program', $program)
            ->active()
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
