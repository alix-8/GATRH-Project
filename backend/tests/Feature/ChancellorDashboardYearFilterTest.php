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
        $this->mock(\App\Services\Escalations\WeeklyEscalationService::class)->shouldReceive('syncStudent')->zeroOrMoreTimes();

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
            $table->boolean('is_active')->default(true); $table->string('inactive_reason')->nullable(); $table->json('roster_history')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('program');
            $table->string('year_level');
            $table->boolean('is_active')->default(true); $table->json('roster_history')->nullable();
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

    public function test_program_week_details_are_available_to_chancellor_and_coordinator_only(): void
    {
        Student::create(['name' => 'Student', 'program' => 'BSIS', 'year_level' => 1]);
        $path = '/api/monitoring/courses/BSIS/1?year=2026&month=10&week=1';

        foreach (['cm_coordinator', 'chancellor'] as $role) {
            $this->actingAs(new User(['role' => $role]))->getJson($path)
                ->assertOk()
                ->assertJsonPath('course.label', 'BSIS 1')
                ->assertJsonCount(3, 'week_details');
        }

        $this->actingAs(new User(['role' => 'secretary']))->getJson($path)->assertForbidden();
    }

    public function test_year_filter_scopes_program_reporting_and_the_overall_summary(): void
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
            ->assertJsonPath('program_year_reporting.0.completion', 8)
            ->assertJsonPath('program_reporting.0.completion', 8)
            ->assertJsonPath('overall_progress.percentage', 8)
            ->assertJsonPath('summary.completed', 0);

        $secondYearResponse
            ->assertOk()
            ->assertJsonPath('program_year_reporting.0.year_level', 2)
            ->assertJsonPath('program_year_reporting.0.status', 'incomplete')
            ->assertJsonPath('program_year_reporting.0.completion', 17)
            ->assertJsonPath('program_reporting.0.completion', 17)
            ->assertJsonPath('overall_progress.percentage', 17)
            ->assertJsonPath('summary.completed', 0);

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
            ['2026-10-30', 4, '2026-10-07', '2026-11-03'],
            ['2026-09-15', 5, '2026-09-02', '2026-10-06'],
            ['2026-01-01', 4, '2026-01-07', '2026-02-03'],
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
        $this->createAttendanceRecord($student, 'pm'); // October 7: Week 1.
        $payload = app(\App\Services\Dashboard\DashboardService::class)->getChancellorDashboardPayload(
            new User(['role' => 'chancellor']), ['date' => '2026-10-30', 'year_level' => 1]
        );
        $expected = ['incomplete', 'no_report', 'no_report', 'no_report'];
        $this->assertSame($expected, $payload['program_year_reporting'][0]['weekly_statuses']);
        $this->assertSame($expected, $payload['program_reporting'][0]['weekly_statuses']);
        $this->assertSame([1, 0, 0, 0], collect($payload['weekly_progress'])->pluck('incomplete')->all());
    }

    public function test_week_icon_requires_every_active_student_in_all_three_gatherings(): void
    {
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $first = Student::create(['name' => 'First', 'program' => 'BSIS', 'year_level' => 2]);
        $second = Student::create(['name' => 'Second', 'program' => 'BSIS', 'year_level' => 2]);
        Student::create(['name' => 'Inactive', 'program' => 'BSIS', 'year_level' => 2, 'is_active' => false]);

        foreach (['pm', 'ws', 'tg'] as $type) {
            $this->addResponse($first, $type);
        }
        $this->assertYearProgress('incomplete', 13);
        $this->addResponse($second, 'pm');
        $this->assertYearProgress('incomplete', 17);
        $this->addResponse($second, 'ws', 'excused');
        $this->assertYearProgress('incomplete', 21);
        $this->addResponse($second, 'tg');
        $this->assertYearProgress('completed', 25);

        // The card counts weekly icons; the program's monthly status stays incomplete.
        $payload = $this->chancellorPayload();
        $this->assertSame('incomplete', $payload['program_reporting'][0]['status']);
        $this->assertSame(25, $payload['program_reporting'][0]['completion']);
        $this->assertSame(1, $payload['summary']['completed']);
        $coordinator = app(\App\Services\Dashboard\CmCoordinatorMonitoringService::class)->getPayload(['year' => 2026, 'month' => 10]);
        $course = collect($coordinator['courses'])->first(fn ($course) => $course['year_level'] === '2' || $course['year_level'] === 2);
        $this->assertSame('completed', $course['weeks'][0]['status']);
    }

    public function test_no_response_records_and_duplicate_responses_do_not_complete_a_week(): void
    {
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $first = Student::create(['name' => 'First', 'program' => 'BSIS', 'year_level' => 2]);
        Student::create(['name' => 'Second', 'program' => 'BSIS', 'year_level' => 2]);
        foreach (['pm', 'ws', 'tg'] as $type) {
            $this->addResponse($first, $type, 'no_response');
        }
        $this->assertYearProgress('no_report', 0);
        foreach (['pm', 'ws', 'tg'] as $type) {
            $this->addResponse($first, $type);
            $this->addResponse($first, $type);
        }
        $this->assertYearProgress('incomplete', 13);
    }

    public function test_draft_responses_can_complete_a_slot_without_approval_or_snapshot_fields(): void
    {
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $student = Student::create(['name' => 'First', 'program' => 'BSIS', 'year_level' => 2]);
        foreach (['pm', 'ws', 'tg'] as $type) {
            \Illuminate\Support\Facades\DB::table('attendance_drafts')->insert([
                'gathering_type' => $type, 'day' => $type === 'pm' ? '2026-10-07' : '2026-10-10',
                'status' => 'draft', 'parsed_data' => json_encode([['student_id' => $student->id, 'status' => 'present']]),
            ]);
        }
        $this->assertYearProgress('completed', 25);
    }

    public function test_overall_graphs_require_all_years_and_gatherings_to_be_complete(): void
    {
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology']);
        foreach ([1, 2] as $year) {
            $student = Student::create(['name' => 'Year '.$year, 'program' => 'ACT', 'year_level' => $year]);
            foreach (['pm', 'ws', 'tg'] as $type) {
                $this->addResponse($student, $type);
            }
        }
        $payload = $this->chancellorPayload();
        $this->assertSame('incomplete', $payload['program_reporting'][0]['status']);
        $this->assertSame(25, $payload['program_reporting'][0]['completion']);
        $this->assertSame(25, $payload['overall_progress']['percentage']);
        $this->assertSame(1, $payload['weekly_progress'][0]['completed']);
        $this->assertSame(1, $payload['summary']['completed']);
        $this->assertSame(0, $payload['summary']['incomplete']);
    }

    public function test_monthly_cards_include_earlier_responses_when_current_week_has_none(): void
    {
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology']);
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $student = Student::create(['name' => 'Earlier response', 'program' => 'BSIS', 'year_level' => 2]);
        $this->addResponse($student, 'pm'); // Week 1 only; the selected date is in Week 4.
        $payload = app(\App\Services\Dashboard\DashboardService::class)->getChancellorDashboardPayload(
            new User(['role' => 'chancellor']), ['date' => '2026-10-30', 'year_level' => 2]
        );
        $this->assertSame(0, $payload['summary']['completed']);
        $this->assertSame(1, $payload['summary']['incomplete']);
        $this->assertSame(1, $payload['summary']['no_report']);
        $this->assertSame(2, $payload['summary']['total_programs']);
        $row = collect($payload['program_year_reporting'])->firstWhere('code', 'BSIS');
        $this->assertSame('incomplete', $row['status']);
        $this->assertSame('no_report', $row['weekly_statuses'][3]);
        $this->assertSame(8, $row['completion']);
        $this->assertSame(1, $payload['weekly_progress'][0]['incomplete']);
        $this->assertSame(0, $payload['weekly_progress'][3]['incomplete']);
    }

    public function test_monthly_completion_requires_every_week_year_and_gathering(): void
    {
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology']);
        foreach ([1, 2] as $year) {
            $student = Student::create(['name' => 'Year '.$year, 'program' => 'ACT', 'year_level' => $year]);
            for ($week = \Carbon\Carbon::parse('2026-10-07'); $week->lte('2026-10-28'); $week->addWeek()) {
                foreach (\App\Support\GatheringCycle::getRangesForWeekStart($week) as $type => $range) {
                    AttendanceRecord::create([
                        'student_id' => $student->id, 'gathering_type' => $type, 'day' => $range['start']->toDateString(),
                        'status' => 'present', 'validation_status' => 'unvalidated', 'submitted_by' => 1,
                    ]);
                }
            }
        }
        $payload = $this->chancellorPayload();
        $this->assertSame(4, $payload['summary']['completed']);
        $this->assertSame(100, $payload['overall_progress']['percentage']);
        $this->assertSame(100, $payload['program_reporting'][0]['completion']);
        $this->assertSame('completed', $payload['program_year_reporting'][0]['status']);
        $this->assertSame(['completed', 'completed', 'completed', 'completed'], $payload['program_year_reporting'][0]['weekly_statuses']);

        AttendanceRecord::query()->orderByDesc('id')->firstOrFail()->delete();
        $payload = $this->chancellorPayload();
        $this->assertSame(3, $payload['summary']['completed']);
        $this->assertSame(1, $payload['summary']['incomplete']);
        $this->assertSame(92, $payload['overall_progress']['percentage']);
        $this->assertSame(92, $payload['program_reporting'][0]['completion']);
        $this->assertSame('incomplete', $payload['program_year_reporting'][0]['status']);
        $this->assertSame('incomplete', $payload['program_year_reporting'][0]['weekly_statuses'][3]);
    }

    public function test_response_percentages_are_weighted_and_all_graphs_follow_the_selected_year(): void
    {
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology']);
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $act = Student::create(['name' => 'ACT Year 1', 'program' => 'ACT', 'year_level' => 1]);
        foreach (['pm', 'ws', 'tg'] as $type) {
            $this->addResponse($act, $type);
        }
        foreach ([1, 2, 3] as $index) {
            $student = Student::create(['name' => 'BSIS Year 1 '.$index, 'program' => 'BSIS', 'year_level' => 1]);
            if ($index === 1) {
                $this->addResponse($student, 'pm');
            }
        }
        $secondYear = Student::create(['name' => 'BSIS Year 2', 'program' => 'BSIS', 'year_level' => 2]);
        $this->addResponse($secondYear, 'pm');
        $dashboard = app(\App\Services\Dashboard\DashboardService::class);
        $user = new User(['role' => 'chancellor']);
        $first = $dashboard->getChancellorDashboardPayload($user, ['date' => '2026-10-30', 'year_level' => 1]);
        $rows = collect($first['program_reporting'])->keyBy('code');
        $this->assertSame(25, $rows['ACT']['completion']); // 3/12
        $this->assertSame(3, $rows['BSIS']['completion']); // 1/36, though no complete gathering slot
        $this->assertSame(4, $first['overall_progress']['responded_students']);
        $this->assertSame(48, $first['overall_progress']['expected_responses']);
        $this->assertSame(8, $first['overall_progress']['percentage']); // 4/48, not an average of program percentages
        $this->assertSame(1, $first['weekly_progress'][0]['completed']);
        $this->assertSame(1, $first['weekly_progress'][0]['incomplete']);

        $second = $dashboard->getChancellorDashboardPayload($user, ['date' => '2026-10-30', 'year_level' => 2]);
        $this->assertSame(1, $second['overall_progress']['responded_students']);
        $this->assertSame(12, $second['overall_progress']['expected_responses']);
        $this->assertSame(8, $second['overall_progress']['percentage']);
        $this->assertSame(0, $second['weekly_progress'][0]['completed']);
        $this->assertSame(1, $second['weekly_progress'][0]['incomplete']);
        $this->assertSame(1, $second['summary']['incomplete']);
        $third = $dashboard->getChancellorDashboardPayload($user, ['date' => '2026-10-30', 'year_level' => 3]);
        $this->assertSame(['BSIS'], collect($third['program_reporting'])->pluck('code')->all());
        $this->assertSame(2, $third['summary']['total_programs']);
        $this->assertSame(0, $third['overall_progress']['percentage']);
    }

    public function test_in_progress_card_counts_incomplete_weekly_icons_instead_of_monthly_programs(): void
    {
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        $student = Student::create(['name' => 'Student', 'program' => 'BSIS', 'year_level' => 2]);
        foreach (['2026-10-07', '2026-10-14', '2026-10-21'] as $day) {
            AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'pm',
                'day' => $day, 'status' => 'present', 'validation_status' => 'unvalidated', 'submitted_by' => 1]);
        }
        $payload = $this->chancellorPayload();
        $this->assertSame(3, $payload['summary']['incomplete']);
        $this->assertSame('incomplete', $payload['program_reporting'][0]['status']);
        $this->assertSame(0, $payload['summary']['completed']);
        foreach (['ws', 'tg'] as $type) $this->addResponse($student, $type);
        $payload = $this->chancellorPayload();
        $this->assertSame(2, $payload['summary']['incomplete']);
        $this->assertSame(1, $payload['summary']['completed']);
        $this->assertSame('incomplete', $payload['program_reporting'][0]['status']);
        $otherYear = app(\App\Services\Dashboard\DashboardService::class)->getChancellorDashboardPayload(
            new User(['role' => 'chancellor']), ['date' => '2026-10-07', 'year_level' => 1]
        );
        $this->assertSame(0, $otherYear['summary']['incomplete']);
    }

    public function test_selected_year_calculates_each_gathering_week_once_for_the_whole_payload(): void
    {
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        foreach ([1, 2, 3, 4] as $year) {
            $student = Student::create(['name' => 'Year '.$year, 'program' => 'BSIS', 'year_level' => $year]);
            $this->addResponse($student, 'pm');
        }
        // Four reporting weeks x three gatherings. Cards, monthly percentages,
        // table icons and weekly chart must reuse those same calculations.
        $this->partialMock(\App\Services\Dashboard\AttendanceResponseSummary::class)
            ->shouldReceive('gathering')->times(12)->passthru();
        $payload = $this->chancellorPayload();
        $this->assertSame(1, $payload['overall_progress']['responded_students']);
        $this->assertSame(12, $payload['overall_progress']['expected_responses']);
        $this->assertSame(1, $payload['summary']['incomplete']);
        $this->assertSame(4, count($payload['weekly_progress']));
    }

    public function test_total_programs_is_an_active_catalog_count_independent_of_filters(): void
    {
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology', 'is_active' => true]);
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems', 'is_active' => true]);
        Program::create(['code' => 'OLD', 'name' => 'Inactive Program', 'is_active' => false]);
        $dashboard = app(\App\Services\Dashboard\DashboardService::class);
        foreach ([1, 2, 3, 4] as $year) {
            $payload = $dashboard->getChancellorDashboardPayload(new User(['role' => 'chancellor']), [
                'date' => '2026-10-30', 'year_level' => $year, 'program' => 'BSIS',
            ]);
            $this->assertSame(2, $payload['summary']['total_programs']);
            $this->assertSame(1, $payload['overall_progress']['total_programs']);
        }
    }

    private function addResponse(Student $student, string $type, string $status = 'present'): void
    {
        AttendanceRecord::create([
            'student_id' => $student->id, 'gathering_type' => $type,
            'day' => $type === 'pm' ? '2026-10-07' : '2026-10-10',
            'status' => $status, 'validation_status' => 'unvalidated', 'submitted_by' => 1,
        ]);
    }

    private function chancellorPayload(): array
    {
        return app(\App\Services\Dashboard\DashboardService::class)->getChancellorDashboardPayload(
            new User(['role' => 'chancellor']), ['date' => '2026-10-07', 'year_level' => 2]
        );
    }

    private function assertYearProgress(string $status, int $percentage): void
    {
        $row = $this->chancellorPayload()['program_year_reporting'][0];
        $this->assertSame($status === 'no_report' ? 'no_report' : 'incomplete', $row['status']);
        $this->assertSame($percentage, $row['completion']);
        $this->assertSame($status, $row['weekly_statuses'][0]);
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
