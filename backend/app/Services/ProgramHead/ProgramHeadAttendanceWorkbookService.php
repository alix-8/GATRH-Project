<?php

namespace App\Services\ProgramHead;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProgramHeadAttendanceWorkbookService
{
    private const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private array $mergeCells = [];

    /** Create a formatted, single-session Excel workbook and return its temp path. */
    public function create(Collection $records): string
    {
        $rows = $this->buildRows($records);
        $lastRow = count($rows);

        $workbookPath = tempnam(sys_get_temp_dir(), 'gatrh-attendance-');

        if ($workbookPath === false) {
            throw new RuntimeException('Unable to create the attendance workbook.');
        }

        $files = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->packageRelationshipsXml(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationshipsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->worksheetXml($rows, $lastRow),
        ];

        try {
            if (file_put_contents($workbookPath, $this->zipFiles($files), LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the attendance workbook.');
            }
        } catch (RuntimeException $exception) {
            @unlink($workbookPath);
            throw $exception;
        }

        return $workbookPath;
    }

    public function mimeType(): string
    {
        return self::MIME_TYPE;
    }

    public function fileExtension(): string
    {
        return 'xlsx';
    }

    /** Create one week or one calendar-month grid matching the supplied attendance template. */
    public function createPeriod(
        Collection $students,
        CarbonImmutable $selectedDate,
        string $period,
        string $program,
        int $yearLevel,
        ?string $programName = null,
        ?int $weekNumber = null,
        ?string $exportNotice = null
    ): string {
        [$weeks] = $this->periodWeeks($selectedDate, $period, $weekNumber);
        $columns = $this->periodColumns($weeks);
        $this->mergeCells = [];
        $rows = $this->periodRows($students, $weeks, $columns, $selectedDate, $programName ?: $program, $yearLevel, $exportNotice);
        $path = tempnam(sys_get_temp_dir(), 'gatrh-attendance-');

        if ($path === false) {
            throw new RuntimeException('Unable to create the attendance workbook.');
        }

        $files = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->packageRelationshipsXml(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationshipsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->periodWorksheetXml($rows, $weeks, $columns),
        ];

        try {
            if (file_put_contents($path, $this->zipFiles($files), LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the attendance workbook.');
            }
        } catch (RuntimeException $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    /** Return Wednesday-to-Tuesday reporting weeks and their attendance query bounds. */
    public function periodBounds(CarbonImmutable $selectedDate, string $period, ?int $weekNumber = null): array
    {
        [, $start, $end] = $this->periodWeeks($selectedDate, $period, $weekNumber);

        return [$start, $end];
    }

    private function periodWeeks(CarbonImmutable $selectedDate, string $period, ?int $weekNumber = null): array
    {
        if ($period === 'week') {
            $monthStart = $selectedDate->startOfMonth();
            $monthEnd = $selectedDate->endOfMonth();
            $firstWeekStart = $monthStart->startOfWeek(Carbon::WEDNESDAY);
            $weekCount = intdiv((int) $firstWeekStart->diffInDays($monthEnd), 7) + 1;
            $selectedWeekNumber = $weekNumber ?? (
                intdiv((int) $firstWeekStart->diffInDays($selectedDate->startOfWeek(Carbon::WEDNESDAY)), 7) + 1
            );

            if ($selectedWeekNumber < 1 || $selectedWeekNumber > $weekCount) {
                throw ValidationException::withMessages([
                    'week' => "Choose a week between 1 and {$weekCount} for the selected month.",
                ]);
            }

            $start = $firstWeekStart->addWeeks($selectedWeekNumber - 1);
            $end = $start->addDays(6);

            return [[[
                'number' => $selectedWeekNumber,
                'start' => $start,
                'end' => $end,
                'label_end' => $end,
            ]], $start, $end];
        }

        $monthStart = $selectedDate->startOfMonth();
        $monthEnd = $selectedDate->endOfMonth();
        $firstWeekStart = $monthStart->startOfWeek(Carbon::WEDNESDAY);
        $weeks = [];

        for ($start = $firstWeekStart, $number = 1; $start->lessThanOrEqualTo($monthEnd); $start = $start->addDays(7), $number++) {
            $end = $start->addDays(6);
            $weeks[] = [
                'number' => $number,
                'start' => $start,
                'end' => $end,
                'label_end' => $end,
            ];
        }

        $lastWeek = $weeks[array_key_last($weeks)];

        return [$weeks, $firstWeekStart, $lastWeek['end']];
    }

    private function periodColumns(array $weeks): array
    {
        $columns = [];
        $columnNumber = 2;

        foreach ($weeks as $week) {
            $week['first_column'] = $columnNumber;

            foreach (['pm', 'ws', 'tg'] as $type) {
                $dateStart = $type === 'pm' ? $week['start'] : $week['start']->addDays(3);
                $dateEnd = $dateStart->addDays($type === 'pm' ? 2 : 1);
                $columns[] = [
                    'week' => $week['number'],
                    'type' => $type,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                    'column' => $columnNumber++,
                ];
            }
        }

        $columns[] = ['type' => 'escalation', 'column' => $columnNumber];

        return $columns;
    }

    private function periodRows(
        Collection $students,
        array $weeks,
        array $columns,
        CarbonImmutable $selectedDate,
        string $program,
        int $yearLevel,
        ?string $exportNotice = null
    ): array {
        $slotColumns = array_slice($columns, 0, -1);
        $lastColumn = $columns[array_key_last($columns)]['column'];
        $yearLabel = $this->ordinalYear($yearLevel);
        $rows = [];

        $rows[] = $this->row([
            $this->cell('A1', 'MONTH OF', 14),
            $this->cell('B1', strtoupper($program . ' ' . $yearLabel), 1),
            $this->cell($this->columnName($lastColumn) . '1', 'INTERVENTION STATUS', 4),
        ], 30);
        $rows[] = $this->row([
            $this->cell('A2', strtoupper($selectedDate->format('F')), 13),
        ], 34);
        $rows[] = $this->row([
            $this->cell('A3', 'WEEK DATES', 2),
        ], 24);
        $rows[] = $this->row([
            $this->cell('A4', 'GATHERING', 2),
        ], 24);
        $rows[] = $this->row([
            $this->cell('A5', 'DATE', 2),
        ], 24);

        foreach ($weeks as $index => $week) {
            $firstColumn = 2 + ($index * 3);
            $lastWeekColumn = $firstColumn + 2;
            $weekDate = $week['start']->format('M j') . ' - ' . $week['label_end']->format('M j, Y');
            $rows[1] = $this->appendCell($rows[1], $this->cell($this->columnName($firstColumn) . '2', 'WEEK ' . $week['number'], 4));
            $rows[2] = $this->appendCell($rows[2], $this->cell($this->columnName($firstColumn) . '3', $weekDate, 16));

            foreach (array_slice($slotColumns, $index * 3, 3) as $slot) {
                $column = $this->columnName($slot['column']);
                $dateLabel = $slot['date_start']->format('M j') . ' - ' . $slot['date_end']->format('M j');
                $rows[3] = $this->appendCell($rows[3], $this->cell($column . '4', strtoupper($slot['type']), $this->gatheringStyle($slot['type'])));
                $rows[4] = $this->appendCell($rows[4], $this->cell($column . '5', $dateLabel, 17));
            }

            $this->mergeCells[] = $this->columnName($firstColumn) . '2:' . $this->columnName($lastWeekColumn) . '2';
            $this->mergeCells[] = $this->columnName($firstColumn) . '3:' . $this->columnName($lastWeekColumn) . '3';
        }

        $this->mergeCells[] = 'B1:' . $this->columnName($lastColumn - 1) . '1';
        $this->mergeCells[] = $this->columnName($lastColumn) . '1:' . $this->columnName($lastColumn) . '5';

        $attendanceByStudentAndSlot = [];

        foreach ($students as $student) {
            foreach ($student->attendanceRecords as $record) {
                $attendanceByStudentAndSlot[$student->id][$record->gathering_type . '|' . ($record->reporting_day ?? $record->day)->toDateString()] = $record;
            }
        }

        foreach ($students as $student) {
            $rowNumber = count($rows) + 1;
            $cells = [$this->cell('A' . $rowNumber, mb_strtoupper($student->name), 8)];

            foreach ($slotColumns as $slot) {
                $record = null;
                for ($day = $slot['date_start']; $day->lessThanOrEqualTo($slot['date_end']); $day = $day->addDay()) {
                    $record = $attendanceByStudentAndSlot[$student->id][$slot['type'] . '|' . $day->toDateString()] ?? null;
                    if ($record) {
                        break;
                    }
                }
                $present = in_array($record?->status, ['present', 'excused'], true);
                $value = $present ? $this->attendanceDetails($record) : ($record ? 'No Response' : ($exportNotice !== null ? 'Missing record' : 'No Response'));
                $style = $present ? $this->attendanceStyle($record) : 7;
                $cells[] = $this->cell($this->columnName($slot['column']) . $rowNumber, $value, $style);
            }

            $level = $student->escalation?->escalation_level ?? 'None';
            $cells[] = $this->cell($this->columnName($lastColumn) . $rowNumber, $level, 9);
            $rows[] = $this->row($cells, 34);
        }

        if ($exportNotice !== null) {
            $noticeRow = count($rows) + 1;
            $rows[] = $this->row([$this->cell('A'.$noticeRow, $exportNotice, 5)], 48);
            $this->mergeCells[] = 'A'.$noticeRow.':'.$this->columnName($lastColumn).$noticeRow;
        }
        return $rows;
    }

    private function periodWorksheetXml(array $rows, array $weeks, array $columns): string
    {
        $lastColumn = $this->columnName($columns[array_key_last($columns)]['column']);
        $lastRow = count($rows);
        $merges = $this->mergeCells;
        $columnWidths = '<col min="1" max="1" width="32" customWidth="1"/>';

        foreach ($columns as $column) {
            $width = $column['type'] === 'escalation' ? 28 : 22;
            $columnWidths .= '<col min="' . $column['column'] . '" max="' . $column['column'] . '" width="' . $width . '" customWidth="1"/>';
        }

        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $merge) {
            $mergeXml .= '<mergeCell ref="' . $merge . '"/>';
        }
        $mergeXml .= '</mergeCells>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane xSplit="1" ySplit="5" topLeftCell="B6" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/><cols>' . $columnWidths . '</cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . $mergeXml
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
            . '</worksheet>';
    }

    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(($number % 26) + 65) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function appendCell(string $row, string $cell): string
    {
        return substr($row, 0, -6) . $cell . '</row>';
    }

    /** Package OOXML files into a ZIP archive without requiring the PHP zip extension. */
    private function zipFiles(array $files): string
    {
        if (!function_exists('gzdeflate')) {
            throw new RuntimeException('The zlib extension is required to create the attendance workbook.');
        }

        $archive = '';
        $centralDirectory = '';
        $offset = 0;
        $dosDate = 0x21; // 1980-01-01, the earliest valid ZIP date.

        foreach ($files as $name => $contents) {
            $name = str_replace('\\', '/', $name);
            $compressed = gzdeflate($contents);

            if ($compressed === false) {
                throw new RuntimeException('Unable to compress the attendance workbook.');
            }

            $nameLength = strlen($name);
            $compressedLength = strlen($compressed);
            $contentLength = strlen($contents);
            $checksum = crc32($contents);

            $archive .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, $dosDate, $checksum, $compressedLength, $contentLength, $nameLength, 0)
                . $name . $compressed;

            $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, $dosDate, $checksum, $compressedLength, $contentLength, $nameLength, 0, 0, 0, 0, 0, $offset)
                . $name;

            $offset = strlen($archive);
        }

        $directoryLength = strlen($centralDirectory);
        $fileCount = count($files);

        return $archive . $centralDirectory
            . pack('VvvvvVVv', 0x06054b50, 0, 0, $fileCount, $fileCount, $directoryLength, $offset, 0);
    }

    private function buildRows(Collection $records): array
    {
        $firstRecord = $records->first();
        $student = $firstRecord?->student;
        $date = $firstRecord ? \App\Support\AttendanceReportingPeriod::day($records) : null;
        $range = $date ? \App\Support\GatheringCycle::getRangesForWeekStart($date)[$firstRecord->gathering_type] : null;
        $rangeLabel = $range ? \App\Support\GatheringCycle::formatRangeLabel($range['start'], $range['end']) : '';
        $weekNumber = $date ? intdiv((int) \App\Support\GatheringCycle::weekStartForDate($date->copy()->startOfMonth())->diffInDays(
            \App\Support\GatheringCycle::weekStartForDate($date)
        ), 7) + 1 : null;
        $year = $firstRecord?->year_level ?? $student?->year_level;
        $programLabel = trim(implode(' ', array_filter([
            $firstRecord?->program ?? $student?->program,
            $year ? $this->ordinalYear((int) $year) : null,
        ])));

        $rows = [
            $this->row([
                $this->cell('A1', 'MONTH OF', 2),
                $this->cell('B1', strtoupper($programLabel ?: 'ATTENDANCE RECORD'), 1),
            ], 30),
            $this->row([
                $this->cell('A2', $date?->format('F') ?? '', 3),
                $this->cell('B2', $weekNumber ? "WEEK {$weekNumber}" : 'ATTENDANCE SESSION', 4),
            ], 34),
            $this->row([
                $this->cell('A3', 'WEEK DATES', 2),
                $this->cell('B3', $rangeLabel, 5),
            ], 24),
            $this->row([
                $this->cell('A4', 'GATHERING', 2),
                $this->cell('B4', $this->gatheringCode($firstRecord?->gathering_type), $this->gatheringStyle($firstRecord?->gathering_type)),
                $this->cell('C4', 'ESCALATION', 2),
            ], 24),
            $this->row([
                $this->cell('A5', 'GATHERING DATES', 2),
                $this->cell('B5', $rangeLabel, 5),
                $this->cell('C5', 'CURRENT LEVEL', 2),
            ], 24),
            $this->row([
                $this->cell('A6', 'STUDENT', 6),
                $this->cell('B6', 'ATTENDANCE DETAILS', 6),
                $this->cell('C6', 'ESCALATION LEVEL', 6),
            ], 26),
        ];

        $records = $records->sortBy(fn ($record) => mb_strtolower($record->student?->name ?? ''));

        foreach ($records as $record) {
            $rowNumber = count($rows) + 1;
            $present = in_array($record->status, ['present', 'excused'], true);
            $style = $present ? $this->attendanceStyle($record) : 7;
            $student = $record->student;
            $escalationLevel = $student?->escalation?->escalation_level ?? 'None';

            $rows[] = $this->row([
                $this->cell("A{$rowNumber}", $student?->name ?? 'Unknown Student', 8),
                $this->cell("B{$rowNumber}", $present ? $this->attendanceDetails($record) : 'No Response', $style),
                $this->cell("C{$rowNumber}", $escalationLevel, 9),
            ], 34);
        }

        return $rows;
    }

    private function attendanceDetails(object $record): string
    {
        $parts = array_filter([
            $record->status === 'excused' ? 'Excused' : null,
            $record->venue,
            $record->day?->format('D'),
            $this->formatTime($record->batch),
        ]);

        return implode(', ', $parts) ?: 'Present';
    }

    private function formatTime(?string $time): string
    {
        if (blank($time) || $time === '---') {
            return '';
        }

        return Carbon::parse($time)->format('g:i A');
    }

    private function gatheringCode(?string $type): string
    {
        return match ($type) {
            'ws' => 'WS',
            'tg' => 'TG',
            'pm' => 'PM',
            default => strtoupper((string) $type),
        };
    }

    private function attendanceStyle(object $record): int
    {
        return strcasecmp(trim((string) ($record->venue ?? '')), 'Special') === 0
            ? 18
            : $this->gatheringStyle($record->gathering_type);
    }

    private function gatheringStyle(?string $type): int
    {
        return match ($type) {
            'ws' => 10,
            'tg' => 11,
            'pm' => 12,
            default => 5,
        };
    }

    private function ordinalYear(int $year): string
    {
        $suffix = match (true) {
            $year % 100 >= 11 && $year % 100 <= 13 => 'th',
            $year % 10 === 1 => 'st',
            $year % 10 === 2 => 'nd',
            $year % 10 === 3 => 'rd',
            default => 'th',
        };

        return "{$year}{$suffix} Year";
    }

    private function row(array $cells, int $height): string
    {
        preg_match('/r="A(\d+)"/', $cells[0], $matches);
        $rowNumber = (int) ($matches[1] ?? 0);

        return '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">'
            . implode('', $cells)
            . '</row>';
    }

    private function cell(string $reference, string $value, int $style): string
    {
        $escapedValue = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr">'
            . '<is><t xml:space="preserve">' . $escapedValue . '</t></is></c>';
    }

    private function worksheetXml(array $rows, int $lastRow): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" '
            . 'activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/>'
            . '<cols><col min="1" max="1" width="32" customWidth="1"/>'
            . '<col min="2" max="2" width="48" customWidth="1"/>'
            . '<col min="3" max="3" width="24" customWidth="1"/></cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<autoFilter ref="A6:C' . $lastRow . '"/>'
            . '<mergeCells count="3"><mergeCell ref="B1:C1"/>'
            . '<mergeCell ref="B2:C2"/><mergeCell ref="B3:C3"/></mergeCells>'
            . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
            . '</worksheet>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function packageRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="9">'
            . '<font><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><sz val="17"/><name val="Arial"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="12"/><name val="Arial"/></font>'
            . '<font><b/><color rgb="FFFFFF00"/><sz val="17"/><name val="Arial"/></font>'
            . '<font><i/><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><color rgb="FFFFFF00"/><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><color rgb="FF0070C0"/><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><i/><color rgb="FF0070C0"/><sz val="10"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="14">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF9FC5E8"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFE933"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF666666"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9EAF7"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF6B26B"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFBDD7EE"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9EAD3"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1B3155"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF4285F4"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFCE4D6"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="19">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="11" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="7" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="9" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="10" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="8" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="12" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="12" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="6" fillId="13" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="7" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="8" fillId="13" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="7" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

