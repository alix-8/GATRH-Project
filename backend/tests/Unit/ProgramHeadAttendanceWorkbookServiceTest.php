<?php

namespace Tests\Unit;

use App\Services\ProgramHeadAttendanceWorkbookService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ProgramHeadAttendanceWorkbookServiceTest extends TestCase
{
    public function test_it_creates_an_excel_compatible_export_when_zip_is_unavailable(): void
    {
        $service = new class extends ProgramHeadAttendanceWorkbookService
        {
            protected function zipArchiveAvailable(): bool
            {
                return false;
            }
        };

        $path = $service->create(new Collection());

        try {
            $content = file_get_contents($path);

            $this->assertSame('xls', $service->fileExtension());
            $this->assertStringContainsString('application/vnd.ms-excel', $service->mimeType());
            $this->assertStringContainsString('ATTENDANCE RECORD', $content);
            $this->assertStringContainsString('ESCALATION LEVEL', $content);
        } finally {
            @unlink($path);
        }
    }
}
