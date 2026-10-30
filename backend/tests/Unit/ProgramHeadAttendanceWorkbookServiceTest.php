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
