<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use ZipArchive;

class ProgramHeadAttendanceWorkbookService
{
    private const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Create a formatted, single-session Excel workbook and return its temp path. */
    public function create(Collection $records): string
    {
        $rows = $this->buildRows($records);
        $lastRow = count($rows);

        if (!$this->zipArchiveAvailable()) {
            return $this->createExcelCompatibleHtml($rows);
        }

        $worksheet = $this->worksheetXml($rows, $lastRow);
        $workbookPath = tempnam(sys_get_temp_dir(), 'gatrh-attendance-');

        if ($workbookPath === false) {
            throw new RuntimeException('Unable to create the attendance workbook.');
        }

        $archive = new ZipArchive();
        $opened = $archive->open($workbookPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            @unlink($workbookPath);
            throw new RuntimeException('Unable to prepare the attendance workbook.');
        }

        $archive->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $archive->addFromString('_rels/.rels', $this->packageRelationshipsXml());
        $archive->addFromString('xl/workbook.xml', $this->workbookXml());
        $archive->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
        $archive->addFromString('xl/styles.xml', $this->stylesXml());
        $archive->addFromString('xl/worksheets/sheet1.xml', $worksheet);
        $archive->close();

        return $workbookPath;
    }

    public function mimeType(): string
    {
        return $this->zipArchiveAvailable()
            ? self::MIME_TYPE
            : 'application/vnd.ms-excel; charset=UTF-8';
    }

    public function fileExtension(): string
    {
        return $this->zipArchiveAvailable() ? 'xlsx' : 'xls';
    }

    protected function zipArchiveAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    private function createExcelCompatibleHtml(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gatrh-attendance-');

        if ($path === false) {
            throw new RuntimeException('Unable to create the attendance export.');
        }

        $tableRows = [];

        foreach ($rows as $index => $rowXml) {
            preg_match_all('/<c r="([A-Z]+)(\d+)" s="(\d+)" t="inlineStr"><is><t xml:space="preserve">(.*?)<\/t><\/is><\/c>/', $rowXml, $cells, PREG_SET_ORDER);
            $rowCells = [];
            $nextColumn = 'A';

            foreach ($cells as $cell) {
                [$cellXml, $column, $rowNumber, $style, $value] = $cell;
                $value = htmlspecialchars(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'), ENT_QUOTES, 'UTF-8');

                if ($column !== $nextColumn) {
                    $rowCells[] = '<td></td>';
                }

                $mergeTitle = (int) $rowNumber <= 3 && $column === 'B';
                $rowCells[] = '<td style="' . $this->htmlCellStyle((int) $style) . '"'
                    . ($mergeTitle ? ' colspan="2"' : '') . '>' . $value . '</td>';
                $nextColumn = $mergeTitle ? 'D' : chr(ord($column) + 1);
            }

            $tableRows[] = '<tr>' . implode('', $rowCells) . '</tr>';
        }

        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:Arial,sans-serif}table{border-collapse:collapse;table-layout:fixed}'
            . 'td{border:1px solid #000;padding:6px;vertical-align:middle;white-space:normal}'
            . 'td:nth-child(1){width:240px}td:nth-child(2){width:360px}td:nth-child(3){width:180px}'
            . '</style></head><body><table><tbody>' . implode('', $tableRows) . '</tbody></table></body></html>';

        if (file_put_contents($path, $html) === false) {
            @unlink($path);
            throw new RuntimeException('Unable to write the attendance export.');
        }

        return $path;
    }

    private function htmlCellStyle(int $style): string
    {
        return match ($style) {
            1 => 'background:#ffe933;font-size:17px;font-weight:bold;text-align:center',
            2 => 'background:#9fc5e8;font-weight:bold',
            3 => 'background:#ffe933;font-size:17px;font-weight:bold;text-align:center',
            4 => 'background:#666;color:#fff;font-size:12px;font-weight:bold;text-align:center',
            5 => 'background:#d9eaf7;font-weight:bold;text-align:center',
            6 => 'background:#1b3155;color:#fff;font-weight:bold;text-align:center',
            7 => 'background:#f6b26b;font-weight:bold;text-align:center',
            8 => 'background:#fff;font-weight:bold',
            9 => 'background:#fff;text-align:center',
            10 => 'background:#fff2cc;text-align:center',
            11 => 'background:#d9ead3;text-align:center',
            12 => 'background:#bdd7ee;text-align:center',
            default => '',
        };
    }

    private function buildRows(Collection $records): array
    {
        $firstRecord = $records->first();
        $student = $firstRecord?->student;
        $date = $firstRecord?->day;
        $weekNumber = $date ? (int) ceil($date->day / 7) : null;
        $programLabel = trim(implode(' ', array_filter([
            $student?->program,
            $student?->year_level ? $this->ordinalYear((int) $student->year_level) : null,
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
                $this->cell('B3', $date?->format('F j, Y') ?? '', 5),
            ], 24),
            $this->row([
                $this->cell('A4', 'GATHERING', 2),
                $this->cell('B4', $this->gatheringCode($firstRecord?->gathering_type), $this->gatheringStyle($firstRecord?->gathering_type)),
                $this->cell('C4', 'ESCALATION', 2),
            ], 24),
            $this->row([
                $this->cell('A5', 'DATE', 2),
                $this->cell('B5', $date?->format('M j, Y') ?? '', 5),
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
            $present = $record->status === 'present';
            $style = $present ? $this->gatheringStyle($record->gathering_type) : 7;
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
            'special' => 'SPECIAL',
            default => strtoupper((string) $type),
        };
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
            . '<mergeCells count="3"><mergeCell ref="B1:C1"/>'
            . '<mergeCell ref="B2:C2"/><mergeCell ref="B3:C3"/></mergeCells>'
            . '<autoFilter ref="A6:C' . $lastRow . '"/>'
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
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><sz val="11"/><name val="Arial"/></font>'
            . '<font><b/><sz val="17"/><name val="Arial"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="12"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="12">'
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
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13">'
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
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
