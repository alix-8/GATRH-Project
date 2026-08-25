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
     * Helper to extract header metadata (Gathering, Program, Year Level) from text block.
     */
    public function extractHeaderMetadata(string $rawText): array
    {
        $metadata = [
            'gathering_type' => 'pm',
            'program'        => null,
            'year_level'     => null,
            'default_date'   => now()->toDateString(),
        ];

        if (preg_match('/Gathering:\s*([A-Za-z0-9_-]+)/i', $rawText, $m)) {
            $metadata['gathering_type'] = strtolower(trim($m[1]));
        }

        if (preg_match('/Program:\s*([A-Za-z0-9_-]+)/i', $rawText, $m)) {
            $metadata['program'] = strtoupper(trim($m[1]));
        }

        if (preg_match('/Year\s*Level:\s*(\d+)/i', $rawText, $m)) {
            $metadata['year_level'] = (int) $m[1];
        }

        return $metadata;
    }

    /**
     * Parses the initial raw text block and builds the full section masterlist.
     */
    public function parseFullText(string $rawText, ?string $overrideProgram = null, ?int $overrideYearLevel = null): array
    {
        $metadata = $this->extractHeaderMetadata($rawText);

        $program = strtoupper(trim($overrideProgram ?? $metadata['program'] ?? ''));
        $yearLevel = (int) ($overrideYearLevel ?? $metadata['year_level'] ?? 0);
        $gatheringType = strtolower(trim($metadata['gathering_type'] ?? 'pm'));
        $defaultDate = $metadata['default_date'];

        $metadata['program'] = $program;
        $metadata['year_level'] = $yearLevel;
        $metadata['gathering_type'] = $gatheringType;

        // Parse line entries
        $reparseResult = $this->reparseLinesInternal($rawText, $program, $yearLevel, $defaultDate, $gatheringType);
        
        // Build complete masterlist against section students
        $sectionData = $this->buildFullSectionRecords($program, $yearLevel, $reparseResult['matched_records'], $defaultDate, $gatheringType);

        $presentCount = count(array_filter($sectionData['records'], fn($r) => $r['status'] === 'present'));

        return [
            'metadata' => $metadata,
            'summary'  => [
                'total_section_students' => $sectionData['total_students'],
                'present_count'          => $presentCount,
                'no_response_count'      => $sectionData['total_students'] - $presentCount,
                'unmatched_lines_count'  => count($reparseResult['unmatched']),
            ],
            'unmatched_lines' => $reparseResult['unmatched'],
            'records'         => $sectionData['records'],
        ];
    }

    /**
     * Internal line-by-line parser for raw lines.
     */
    private function reparseLinesInternal(string $rawText, string $program, int $yearLevel, string $defaultDate, string $gatheringType): array
    {
        $lines = explode("\n", trim($rawText));
        $newlyMatched = [];
        $unmatchedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_contains($line, '---') || str_contains($line, '[')) {
                continue;
            }

            $result = $this->buildRecordFromLine($line, $program, $yearLevel, $defaultDate, $gatheringType);

            if ($result['matched']) {
                $studentId = $result['record']['student_id'];
                $newlyMatched[$studentId] = $result['record'];
            } elseif (!empty($result['unmatched_data'])) {
                $unmatchedLines[] = $result['unmatched_data'];
            }
        }

        return [
            'matched_records' => $newlyMatched,
            'unmatched'       => $unmatchedLines,
        ];
    }

    /**
     * Reparses delta text lines and patches existing SQLite staged records (Accepts 6 arguments).
     */
    public function reparseLines(string $rawText, string $program, int $yearLevel, string $defaultDate, string $gatheringType, array $existingStaged = []): array
    {
        $internalResult = $this->reparseLinesInternal($rawText, $program, $yearLevel, $defaultDate, $gatheringType);
        $newlyMatched = $internalResult['matched_records'];

        // Patch newly matched records over existing staged draft
        foreach ($newlyMatched as $studentId => $record) {
            $existingStaged[$studentId] = $record;
        }

        $allRecords = array_values($existingStaged);
        $presentCount = count(array_filter($allRecords, fn($r) => $r['status'] === 'present'));

        return [
            'summary' => [
                'total_staged_records'  => count($allRecords),
                'present_count'         => $presentCount,
                'no_response_count'     => count($allRecords) - $presentCount,
                'unmatched_lines_count' => count($internalResult['unmatched']),
            ],
            'unmatched_lines' => $internalResult['unmatched'],
            'records'         => $allRecords,
        ];
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

        $hasAttendanceDetails = !empty($formattedBatch) || !empty($venue);
        $status = $hasAttendanceDetails ? 'present' : 'no_response';

        return [
            'matched' => true,
            'record'  => [
                'student_id'     => $student->id,
                'student_name'   => $student->name,
                'gathering_type' => $gatheringType,
                'batch'          => $formattedBatch,
                'venue'          => $venue,
                'day'            => $date,
                'status'         => $status,
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