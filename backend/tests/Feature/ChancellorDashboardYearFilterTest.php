<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChancellorDashboardYearFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->string('program')->nullable();
            $table->string('year_level')->nullable();
            $table->string('contact_number')->nullable();
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('program');
            $table->string('year_level');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('gathering_type');
            $table->date('day');
            $table->date('reporting_day')->nullable();
            $table->string('status');
            $table->string('validation_status');
            $table->unsignedBigInteger('submitted_by');
            $table->timestamps();
        });

        Schema::create('escalations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('escalation_level');
            $table->unsignedInteger('no_response_count');
            $table->timestamps();
        });
        Schema::create('attendance_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('gathering_type');
            $table->string('status');
            $table->date('day');
            $table->text('parsed_data')->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach (['attendance_drafts', 'escalations', 'attendance_records', 'students', 'programs', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_year_filter_scopes_program_reporting_but_not_the_overall_summary(): void
    {
        $chancellor = User::create([
            'name' => 'Chancellor',
            'email' => 'chancellor@example.com',
            'password' => 'password',
            'role' => 'chancellor',
            'contact_number' => '0000000000',
        ]);

        Program::create([
            'code' => 'ACT',
            'name' => 'Associate in Computer Technology',
        ]);
        Program::create([
            'code' => 'BSIS',
            'name' => 'Bachelor of Science in Information Systems',
        ]);

        $firstYearStudent = Student::create([
            'name' => 'First Year Student',
            'program' => 'ACT',
            'year_level' => '1',
        ]);
        $secondYearStudent = Student::create([
            'name' => 'Second Year Student',
            'program' => 'ACT',
            'year_level' => '2',
        ]);

        $this->createAttendanceRecord($firstYearStudent, 'pm');
        $this->createAttendanceRecord($secondYearStudent, 'ws');
        $this->createAttendanceRecord($secondYearStudent, 'tg');

        $firstYearResponse = $this->actingAs($chancellor)
            ->getJson('/api/dashboard?date=2026-10-07&year_level=1');
        $secondYearResponse = $this->getJson('/api/dashboard?date=2026-10-07&year_level=2');

        $firstYearResponse
            ->assertOk()
            ->assertJsonPath('program_year_reporting.0.year_level', 1)
            ->assertJsonPath('program_year_reporting.0.status', 'incomplete')
            ->assertJsonPath('program_year_reporting.0.completion', 33)
            ->assertJsonPath('summary.completed', 1);

        $secondYearResponse
            ->assertOk()
            ->assertJsonPath('program_year_reporting.0.year_level', 2)
            ->assertJsonPath('program_year_reporting.0.status', 'incomplete')
            ->assertJsonPath('program_year_reporting.0.completion', 67)
            ->assertJsonPath('summary.completed', 1);

        $thirdYearResponse = $this->getJson('/api/dashboard?date=2026-10-07&year_level=3');
        $thirdYearResponse
            ->assertOk()
            ->assertJsonCount(1, 'program_year_reporting')
            ->assertJsonPath('program_year_reporting.0.code', 'BSIS');

        $fourthYearResponse = $this->getJson('/api/dashboard?date=2026-10-07&year_level=4');
        $fourthYearResponse
            ->assertOk()
            ->assertJsonCount(1, 'program_year_reporting')
            ->assertJsonPath('program_year_reporting.0.code', 'BSIS');
    }

    private function createAttendanceRecord(Student $student, string $gatheringType): void
    {
        $submitter = User::create([
            'name' => "Secretary {$student->year_level} {$gatheringType}",
            'email' => "secretary-{$student->year_level}-{$gatheringType}@example.com",
            'password' => 'password',
            'role' => 'secretary',
            'program' => 'ACT',
            'year_level' => $student->year_level,
        ]);

        AttendanceRecord::create([
            'student_id' => $student->id,
            'gathering_type' => $gatheringType,
            'day' => $gatheringType === 'pm' ? '2026-10-07' : '2026-10-10',
            'status' => 'present',
            'validation_status' => 'validated',
            'submitted_by' => $submitter->id,
        ]);
    }
}
