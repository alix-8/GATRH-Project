<?php

namespace Tests\Feature;

use App\Models\{AttendanceRecord, Program, Student, User};
use App\Services\Admin\{AdminProgramService, AdminStudentService};
use App\Services\Audit\AuditLogService;
use App\Services\Dashboard\{CmCoordinatorMonitoringService, DashboardService, ReportingRoster};
use App\Services\ProgramHead\ProgramHeadRecordsExportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class InactiveRosterVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->mock(AuditLogService::class)->shouldReceive('record', 'recordAction')->zeroOrMoreTimes();
        $this->mock(\App\Services\Escalations\WeeklyEscalationService::class)->shouldReceive('syncStudent')->zeroOrMoreTimes();
        Schema::create('programs', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name');
            $t->boolean('is_active')->default(true); $t->integer('max_year_level')->default(4);
            $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('program'); $t->string('year_level');
            $t->boolean('is_active')->default(true); $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password');
            $t->string('role'); $t->string('program')->nullable(); $t->string('year_level')->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('program_user', function (Blueprint $t) { $t->integer('program_id'); $t->integer('user_id'); });
        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id(); $t->integer('student_id'); $t->string('gathering_type'); $t->date('day');
            $t->date('reporting_day')->nullable(); $t->string('program')->nullable(); $t->integer('year_level')->nullable();
            $t->string('batch_id')->nullable(); $t->string('batch')->nullable(); $t->string('venue')->nullable();
            $t->integer('submitted_by')->nullable(); $t->integer('validated_by')->nullable();
            $t->string('status')->default('present'); $t->string('validation_status')->default('validated'); $t->timestamps();
        });
        Schema::create('attendance_drafts', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->string('gathering_type'); $t->date('day');
            $t->string('status'); $t->json('parsed_data')->nullable(); $t->timestamps();
        });
        Schema::create('escalations', function (Blueprint $t) {
            $t->id(); $t->integer('student_id'); $t->string('escalation_level'); $t->integer('no_response_count');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function program(string $code, bool $active = true): Program
    {
        return Program::create(['code' => $code, 'name' => $code, 'is_active' => $active]);
    }

    private function student(string $name, string $program = 'BSIS', bool $active = true): Student
    {
        return Student::create(['name' => $name, 'program' => $program, 'year_level' => 1, 'is_active' => $active]);
    }

    public function test_current_lists_exclude_inactive_students_and_inactive_program_members(): void
    {
        $this->program('BSIS'); $this->program('OLD', false);
        $active = $this->student('Active'); $this->student('Inactive', 'BSIS', false);
        $this->student('Active flag in inactive program', 'OLD');
        $user = new User(['role' => 'chancellor', 'is_active' => true]); $user->id = 1;
        $this->actingAs($user)->getJson('/api/students')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $active->id);
        $this->assertSame([$active->id], Student::active()->pluck('id')->all());
        $payload = app(CmCoordinatorMonitoringService::class)->getPayload(['year' => 2026, 'month' => 10]);
        $this->assertSame(['BSIS'], $payload['programs']->pluck('code')->all());
        $this->assertNull(app(CmCoordinatorMonitoringService::class)->getCourseDetails('OLD', 1, ['year' => 2026, 'month' => 10]));
    }

    public function test_admin_defaults_to_active_and_keeps_explicit_inactive_filter(): void
    {
        $this->program('BSIS'); $this->program('OLD', false);
        $this->student('Active'); $inactive = $this->student('Inactive', 'BSIS', false);
        $studentService = app(AdminStudentService::class);
        $this->assertSame(1, $studentService->getPageData([])['students']->total());
        $archive = $studentService->getPageData(['is_active' => false]);
        $this->assertSame([$inactive->id], $archive['students']->pluck('id')->all());
        $this->assertSame(['BSIS'], $archive['programs']->pluck('code')->all());
        $programService = app(AdminProgramService::class);
        $this->assertSame(['BSIS'], $programService->getPageData([])['programs']->pluck('code')->all());
        $this->assertSame(['OLD'], $programService->getPageData(['is_active' => false])['programs']->pluck('code')->all());
    }

    public function test_snapshot_separates_gathering_responses_without_changing_latest_summary(): void
    {
        app(\App\Services\Escalations\WeeklyEscalationService::class)
            ->shouldReceive('countsForStudents')->andReturn(collect());
        app(\App\Services\Escalations\WeeklyEscalationService::class)
            ->shouldReceive('syncDraft')->zeroOrMoreTimes();
        $this->program('BSIS');
        $student = $this->student('Different gathering responses');
        $user = new User(['role' => 'secretary', 'program' => 'BSIS', 'year_level' => 1, 'is_active' => true]);
        $user->id = 1;
        AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'status' => 'present']);
        AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'ws',
            'day' => '2026-10-31', 'status' => 'no_response']);

        $request = \Illuminate\Http\Request::create('/api/students/attendance-snapshot');
        $request->setUserResolver(fn () => $user);
        $payload = app(\App\Http\Controllers\Students\StudentController::class)->attendanceSnapshot($request)->getData(true);
        $this->assertSame('No Response', $payload['students'][0]['response']);
        $this->assertSame(['pm' => 'Responded', 'ws' => 'No Response', 'tg' => null], $payload['students'][0]['responses_by_gathering']);

        \App\Models\AttendanceDraft::create(['user_id' => 1, 'gathering_type' => 'ws',
            'day' => '2026-10-31', 'status' => 'draft',
            'parsed_data' => [['student_id' => $student->id, 'status' => 'present']]]);
        Carbon::setTestNow('2026-10-31 12:01:00');
        \App\Models\AttendanceDraft::query()->update(['updated_at' => now()]);
        $payload = app(\App\Http\Controllers\Students\StudentController::class)->attendanceSnapshot($request)->getData(true);
        $this->assertSame('Responded', $payload['students'][0]['responses_by_gathering']['ws']);
        $this->assertSame('Responded', $payload['students'][0]['responses_by_gathering']['pm']);
    }

    public function test_status_history_preserves_past_expectations_and_reactivation_gaps(): void
    {
        $program = $this->program('BSIS'); $student = $this->student('Student');
        Carbon::setTestNow('2026-10-20 12:00:00'); $student->update(['is_active' => false]);
        $this->assertTrue($student->wasActiveOn('2026-10-15'));
        $this->assertFalse($student->wasActiveOn('2026-10-21'));
        Carbon::setTestNow('2026-10-28 12:00:00'); $student->update(['is_active' => true]);
        $this->assertFalse($student->wasActiveOn('2026-10-21'));
        $this->assertTrue($student->wasActiveOn('2026-10-29'));
        $roster = app(ReportingRoster::class);
        $this->assertSame([$student->id], $roster->studentIds(collect([$student]), collect(), '2026-10-15', $program));
        $this->assertSame([], $roster->studentIds(collect([$student]), collect(), '2026-10-21', $program));
        $program->update(['is_active' => false]);
        $this->assertTrue($program->wasActiveOn('2026-10-15'));
        $this->assertFalse($program->wasActiveOn('2026-10-29'));
    }

    public function test_historical_records_and_period_exports_keep_inactive_promoted_students(): void
    {
        $program = $this->program('BSIS'); $student = $this->student('Historical');
        AttendanceRecord::create(['student_id' => $student->id, 'program' => 'BSIS', 'year_level' => 1,
            'gathering_type' => 'pm', 'day' => '2026-09-16', 'reporting_day' => '2026-09-16',
            'validation_status' => 'validated', 'batch_id' => 'old-batch', 'status' => 'present']);
        $student->update(['is_active' => false, 'year_level' => 2]);
        $program->update(['is_active' => false]);
        $head = new User(['role' => 'program_head', 'program' => 'BSIS']); $head->id = 1;
        $service = app(ProgramHeadRecordsExportService::class);
        $this->assertSame(1, $service->query($head, ['batch_id' => 'old-batch'])->count());
        $students = $service->periodStudents($head, 'BSIS', 1, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        $this->assertSame([$student->id], $students->pluck('id')->all());
        $this->assertCount(1, $students->first()->attendanceRecords);
        $this->assertSame(['BSIS'], app(ReportingRoster::class)->programsForMonth(Carbon::parse('2026-09-01'))->pluck('code')->all());
        $this->assertCount(0, app(ReportingRoster::class)->programsForMonth(Carbon::parse('2026-10-01')));
    }

    public function test_inactivation_does_not_rewrite_chancellor_historical_percentages(): void
    {
        $this->program('BSIS'); $student = $this->student('Historical response');
        AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'pm', 'day' => '2026-09-16',
            'reporting_day' => '2026-09-16', 'status' => 'present', 'validation_status' => 'validated']);
        $service = app(DashboardService::class); $head = new User(['role' => 'chancellor']);
        $filters = ['date' => '2026-09-30', 'year_level' => 1];
        $before = $service->getChancellorDashboardPayload($head, $filters)['overall_progress'];
        $student->update(['is_active' => false]);
        $after = $service->getChancellorDashboardPayload($head, $filters)['overall_progress'];
        $this->assertSame($before, $after);
        $this->assertGreaterThan(0, $after['percentage']);
    }

    public function test_program_inactivation_hides_members_without_deleting_saved_attendance(): void
    {
        $program = $this->program('BSIS'); $student = $this->student('Member');
        $record = AttendanceRecord::create(['student_id' => $student->id, 'program' => 'BSIS', 'year_level' => 1,
            'gathering_type' => 'pm', 'day' => '2026-10-14', 'status' => 'present', 'validation_status' => 'validated']);
        app(AdminProgramService::class)->update($program, ['is_active' => false]);
        $this->assertFalse($student->fresh()->is_active);
        $this->assertTrue($student->fresh()->wasActiveOn('2026-10-14'));
        $this->assertSame(0, Student::active()->count());
        $this->assertNotNull($record->fresh());
        $this->assertFalse($program->fresh()->is_active);
    }

    public function test_add_student_rejects_an_inactive_program(): void
    {
        $this->program('OLD', false);
        $admin = new User(['role' => 'admin', 'is_active' => true]); $admin->id = 1;
        $this->actingAs($admin)->postJson('/api/admin/students', [
            'name' => 'New student', 'program' => 'OLD', 'year_level' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('program');
        $this->assertDatabaseCount('students', 0);
    }
}
