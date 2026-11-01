<?php

namespace Tests\Unit;

use App\Services\ProgramHead\ProgramHeadAttendanceWorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ProgramHeadAttendanceWorkbookServiceTest extends TestCase
{
    public function test_it_creates_a_valid_xlsx_package_without_the_zip_extension(): void
    {
        $service = new ProgramHeadAttendanceWorkbookService();
        $path = $service->create(new Collection());

        try {
            $content = file_get_contents($path);

            $this->assertSame('xlsx', $service->fileExtension());
            $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $service->mimeType());
            $this->assertSame("PK\x03\x04", substr($content, 0, 4));
            $this->assertStringContainsString('[Content_Types].xml', $content);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_builds_week_and_month_exports_for_the_wednesday_reporting_cycle(): void
    {
        $service = new ProgramHeadAttendanceWorkbookService();
        $selectedDate = CarbonImmutable::parse('2026-09-20');

        [$weekStart, $weekEnd] = $service->periodBounds($selectedDate, 'week');
        [$fifthWeekStart, $fifthWeekEnd] = $service->periodBounds($selectedDate, 'week', 5);
        [$monthStart, $monthEnd] = $service->periodBounds($selectedDate, 'month');

        $this->assertSame('2026-09-16', $weekStart->toDateString());
        $this->assertSame('2026-09-22', $weekEnd->toDateString());
        $this->assertSame('2026-09-23', $fifthWeekStart->toDateString());
        $this->assertSame('2026-09-29', $fifthWeekEnd->toDateString());
        $this->assertSame('2026-08-26', $monthStart->toDateString());
        $this->assertSame('2026-10-06', $monthEnd->toDateString());

        $path = $service->createPeriod(new Collection(), $selectedDate, 'month', 'BSIS', 2);

        try {
            $content = file_get_contents($path);
            $this->assertSame("PK\x03\x04", substr($content, 0, 4));
        } finally {
            @unlink($path);
        }
    }

    public function test_period_worksheet_avoids_filters_over_merged_template_headers(): void
    {
        $service = new ProgramHeadAttendanceWorkbookService();
        $path = $service->createPeriod(
            new Collection(),
            CarbonImmutable::parse('2026-09-01'),
            'week',
            'BSIS',
            3,
            null,
            1
        );

        try {
            $worksheet = $this->readZipEntry(
                file_get_contents($path),
                'xl/worksheets/sheet1.xml'
            );

            $this->assertNotFalse($worksheet);
            $this->assertStringContainsString('<mergeCells', $worksheet);
            $this->assertStringNotContainsString('<autoFilter', $worksheet);
            $this->assertStringContainsString('<c r="B3" s="16"', $worksheet);
            $this->assertStringContainsString('<c r="B5" s="17"', $worksheet);
        } finally {
            @unlink($path);
        }
    }

    public function test_session_workbook_shows_the_full_gathering_range_and_preserves_actual_attendance_dates(): void
    {
        $service = new ProgramHeadAttendanceWorkbookService();
        foreach ([
            ['pm', '2026-10-28', 'October 28-30, 2026'],
            ['ws', '2026-10-31', 'October 31-November 1, 2026'],
            ['tg', '2026-10-31', 'October 31-November 1, 2026'],
        ] as [$type, $reportingDay, $expectedRange]) {
            $record = (object) [
                'student' => (object) ['name' => 'Test Student', 'program' => 'BSIS', 'year_level' => 2, 'escalation' => null],
                'program' => 'BSIS', 'year_level' => 1,
                'gathering_type' => $type, 'reporting_day' => \Carbon\Carbon::parse($reportingDay),
                'day' => \Carbon\Carbon::parse('2026-10-26'), 'venue' => 'Special', 'batch' => '08:00:00', 'status' => 'present',
            ];
            $path = $service->create(new Collection([$record]));
            try {
                $worksheet = $this->readZipEntry(file_get_contents($path), 'xl/worksheets/sheet1.xml');
                $this->assertStringContainsString('<c r="B3" s="5" t="inlineStr"><is><t xml:space="preserve">'.$expectedRange.'</t>', $worksheet);
                $this->assertStringContainsString('GATHERING DATES', $worksheet);
                $this->assertStringContainsString('BSIS 1ST', $worksheet);
                $this->assertStringContainsString('Special, Mon, 8:00 AM', $worksheet);
                $this->assertStringContainsString('WEEK 5', $worksheet);
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_monthly_reminder_workbook_omits_approval_labels_but_distinguishes_missing_and_no_response(): void
    {
        $records = [];
        foreach ([['pm', 'draft', 'present'], ['ws', 'validated', 'no_response'], ['tg', 'unvalidated', 'excused']] as [$type, $approval, $status]) {
            $records[] = (object) [
                'gathering_type' => $type, 'reporting_day' => \Carbon\Carbon::parse($type === 'pm' ? '2026-10-07' : '2026-10-10'),
                'day' => \Carbon\Carbon::parse('2026-10-07'), 'venue' => 'Special', 'batch' => '08:00:00',
                'status' => $status, 'validation_status' => $approval,
            ];
        }
        $student = (object) ['id' => 1, 'name' => 'Student', 'escalation' => null, 'attendanceRecords' => $records];
        $service = new ProgramHeadAttendanceWorkbookService();
        $path = $service->createPeriod(new Collection([$student]), CarbonImmutable::parse('2026-10-01'), 'month', 'BSIS', 1, null, null, 'Coverage: All reporting weeks · PM, WS, TG. Students included: 1.');
        try {
            $sheet = $this->readZipEntry(file_get_contents($path), 'xl/worksheets/sheet1.xml');
            foreach (['Missing record', 'No Response', 'Excused', 'Coverage: All reporting weeks', 'Students included: 1.'] as $label) {
                $this->assertStringContainsString($label, $sheet);
            }
            foreach (['[Draft]', '[Approved]', '[Pending approval]'] as $label) {
                $this->assertStringNotContainsString($label, $sheet);
            }
            $this->assertStringContainsString('s="18"', $sheet);
        } finally {
            @unlink($path);
        }
    }

    public function test_period_exports_repeat_pm_ws_tg_with_the_correct_date_ranges(): void
    {
        $service = new ProgramHeadAttendanceWorkbookService();
        foreach (['week', 'month'] as $period) {
            $path = $service->createPeriod(new Collection(), CarbonImmutable::parse('2026-10-28'), $period, 'BSIS', 1);
            try {
                $sheet = $this->readZipEntry(file_get_contents($path), 'xl/worksheets/sheet1.xml');
                preg_match('/<row r="4"[^>]*>(.*?)<\/row>/s', $sheet, $header);
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $header[1], $labels);
                $types = array_slice($labels[1], 1);
                $this->assertNotEmpty($types);
                foreach (array_chunk($types, 3) as $group) {
                    $this->assertSame(['PM', 'WS', 'TG'], $group);
                }
                $pmRange = $period === 'week' ? 'Oct 28 - Oct 30' : 'Sep 30 - Oct 2';
                $weekendRange = $period === 'week' ? 'Oct 31 - Nov 1' : 'Oct 3 - Oct 4';
                $this->assertMatchesRegularExpression('/<c r="B5"[^>]*>.*?'.preg_quote($pmRange, '/').'<\/t>/', $sheet);
                foreach (['C5', 'D5'] as $cell) {
                    $this->assertMatchesRegularExpression('/<c r="'.$cell.'"[^>]*>.*?'.preg_quote($weekendRange, '/').'<\/t>/', $sheet);
                }
            } finally {
                @unlink($path);
            }
        }
    }

    private function readZipEntry(string $archive, string $expectedName): string|false
    {
        $offset = 0;
        $archiveLength = strlen($archive);

        while ($offset + 30 <= $archiveLength && substr($archive, $offset, 4) === "PK\x03\x04") {
            $header = unpack(
                'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength',
                substr($archive, $offset, 30)
            );
            $nameOffset = $offset + 30;
            $name = substr($archive, $nameOffset, $header['nameLength']);
            $contentOffset = $nameOffset + $header['nameLength'] + $header['extraLength'];
            $compressed = substr($archive, $contentOffset, $header['compressedSize']);

            if ($name === $expectedName) {
                return $header['method'] === 8 ? gzinflate($compressed) : $compressed;
            }

            $offset = $contentOffset + $header['compressedSize'];
        }

        return false;
    }
}
