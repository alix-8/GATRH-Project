<?php

namespace Tests\Unit;

use App\Services\ProgramHeadAttendanceWorkbookService;
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

    public function test_it_builds_week_and_month_exports_for_the_saturday_reporting_cycle(): void
    {
        $service = new ProgramHeadAttendanceWorkbookService();
        $selectedDate = CarbonImmutable::parse('2026-09-20');

        [$weekStart, $weekEnd] = $service->periodBounds($selectedDate, 'week');
        [$monthStart, $monthEnd] = $service->periodBounds($selectedDate, 'month');

        $this->assertSame('2026-09-19', $weekStart->toDateString());
        $this->assertSame('2026-09-23', $weekEnd->toDateString());
        $this->assertSame('2026-08-29', $monthStart->toDateString());
        $this->assertSame('2026-09-30', $monthEnd->toDateString());

        $path = $service->createPeriod(new Collection(), $selectedDate, 'month', 'BSIS', 2);

        try {
            $content = file_get_contents($path);
            $this->assertSame("PK\x03\x04", substr($content, 0, 4));
        } finally {
            @unlink($path);
        }
    }
}
