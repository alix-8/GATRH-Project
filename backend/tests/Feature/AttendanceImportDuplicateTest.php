<?php

namespace Tests\Feature;

use App\Http\Controllers\Attendance\AttendanceController;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\AttendanceParsingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceImportDuplicateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('program');
            $table->integer('year_level');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('program');
            $table->integer('year_level');
        });
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('gathering_type');
            $table->date('day');
            $table->string('validation_status');
        });
        Schema::create('attendance_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('gathering_type');
            $table->string('status');
            $table->date('day');
        });
        Student::create(['name' => 'Juan Dela Cruz', 'program' => 'BSIS', 'year_level' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['attendance_drafts', 'attendance_records', 'users', 'students'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_individual_import_rejects_repeated_student_even_when_confirmed(): void
    {
        $this->assertDuplicateRejected('parseText', "1. Juan Dela Cruz | | |\n2. Juan Dela Cruz | 8AM | Main Hall | Wednesday");
    }

    public function test_weekly_import_rejects_repeated_student_even_when_confirmed(): void
    {
        $this->assertDuplicateRejected('parseBatchText', "1. Juan Dela Cruz\nPM: Wednesday | 8AM | Main Hall\n2. Juan Dela Cruz\nPM: Wednesday | 9AM | Main Hall");
    }

    public function test_weekly_parser_keeps_one_record_per_student_and_gathering(): void
    {
        $parsed = app(AttendanceParsingService::class)->parseBatchText(
            "1. Juan Dela Cruz\nPM: Wednesday | 8AM | Main Hall\n2. Juan Dela Cruz\nPM: Wednesday | 9AM | Main Hall",
            'BSIS', 1
        );

        $this->assertCount(1, $parsed['records']['pm']);
        $this->assertSame('08:00:00', $parsed['records']['pm'][0]['batch']);
        $this->assertCount(1, $parsed['format_errors']);
    }

    public function test_weekly_parser_accepts_distinct_students(): void
    {
        Student::create(['name' => 'Maria Santos', 'program' => 'BSIS', 'year_level' => 1]);
        $parsed = app(AttendanceParsingService::class)->parseBatchText(
            "1. Juan Dela Cruz\nPM: Wednesday | 8AM | Main Hall\n2. Maria Santos\nPM: Wednesday | 9AM | Main Hall",
            'BSIS', 1
        );

        $this->assertCount(2, $parsed['records']['pm']);
        $this->assertSame([], $parsed['format_errors']);
    }

    private function assertDuplicateRejected(string $method, string $text): void
    {
        $request = Request::create('/attendance/import', 'POST', [
            'raw_text' => $text,
            'gathering_type' => 'pm',
            'confirm_blank' => true,
            'override' => true,
        ]);
        $request->setUserResolver(fn () => new User(['program' => 'BSIS', 'year_level' => 1]));

        $response = app(AttendanceController::class)->{$method}($request);
        $body = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($body['requires_confirmation']);
        $this->assertSame('Juan Dela Cruz', $body['format_errors'][0]['student_name']);
        $this->assertStringContainsString('Duplicate student entry', $body['format_errors'][0]['errors'][0]['reason']);
        $this->assertDatabaseCount('attendance_drafts', 0);
        $this->assertDatabaseCount('attendance_records', 0);
    }
}
