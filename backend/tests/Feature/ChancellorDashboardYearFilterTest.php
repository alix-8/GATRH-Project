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

    public function test_monthly_weeks_are_dynamic_and_match_coordinator_reporting_ranges(): void
    {
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $user = new User(['role' => 'chancellor']);
        $dashboard = app(\App\Services\Dashboard\DashboardService::class);
        $coordinator = app(\App\Services\Dashboard\CmCoordinatorMonitoringService::class);

        foreach ([
            ['2023-02-15', 4, '2023-02-01', '2023-02-28'],
            ['2026-10-30', 5, '2026-09-30', '2026-11-03'],
            ['2026-09-15', 6, '2026-08-26', '2026-10-06'],
            ['2026-01-01', 5, '2025-12-31', '2026-02-03'],
        ] as [$date, $count, $first, $last]) {
            $payload = $dashboard->getChancellorDashboardPayload($user, ['date' => $date, 'year_level' => 1]);
            $weeks = collect($payload['reporting_weeks']);
            $this->assertCount($count, $weeks);
            $this->assertSame($first, $weeks->first()['week_start']);
            $this->assertSame($last, $weeks->last()['week_end']);
            $this->assertSame($weeks->pluck('week_start')->all(), collect($payload['weekly_progress'])->pluck('week_start')->all());
            $this->assertCount($count, $payload['program_year_reporting'][0]['weekly_statuses']);
            $month = \Carbon\Carbon::parse($date);
            $monitoring = $coordinator->getPayload(['year' => $month->year, 'month' => $month->month]);
            $this->assertSame(collect($monitoring['weeks'])->pluck('start')->all(), $weeks->pluck('week_start')->all());
            $this->assertSame(collect($monitoring['weeks'])->pluck('end')->all(), $weeks->pluck('week_end')->all());
        }
    }

    public function test_weekly_statuses_use_the_same_monthly_slots_as_the_chart(): void
    {
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology']);
        $student = Student::create(['name' => 'Student', 'program' => 'ACT', 'year_level' => 1]);
        $this->createAttendanceRecord($student, 'pm'); // October 7: Week 2, not Week 4.
        $payload = app(\App\Services\Dashboard\DashboardService::class)->getChancellorDashboardPayload(
            new User(['role' => 'chancellor']), ['date' => '2026-10-30', 'year_level' => 1]
        );
        $expected = ['no_report', 'incomplete', 'no_report', 'no_report', 'no_report'];
        $this->assertSame($expected, $payload['program_year_reporting'][0]['weekly_statuses']);
        $this->assertSame($expected, $payload['program_reporting'][0]['weekly_statuses']);
        $this->assertSame([0, 1, 0, 0, 0], collect($payload['weekly_progress'])->pluck('incomplete')->all());
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
