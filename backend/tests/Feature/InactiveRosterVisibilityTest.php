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
            $t->timestamps();
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

    public function test_recent_activities_keep_withdrawals_scoped_after_the_draft_is_gone(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->integer('actor_id'); $table->string('action');
            $table->string('target_table'); $table->string('target_id'); $table->string('result');
            $table->text('details')->nullable(); $table->timestamps();
        });
        $this->program('BSIS');
        DB::table('users')->insert(['id' => 5, 'name' => 'Secretary Mel', 'email' => 'mel@laverdad.edu.ph',
            'password' => 'unused', 'role' => 'secretary', 'program' => 'BSIS', 'year_level' => 1]);
        foreach ([['BSIS', '2026-10-28'], ['ACT', '2026-10-28'], ['BSIS', '2026-09-16']] as $index => [$program, $day]) {
            \App\Models\AuditLog::create(['actor_id' => 5, 'action' => 'Unsubmitted attendance submission',
                'target_table' => 'attendance_records', 'target_id' => 'batch-'.$index, 'result' => 'success',
                'details' => json_encode(['submissions' => [['program' => $program, 'year_level' => 1,
                    'gathering_type' => 'pm', 'reporting_day' => $day]]])]);
        }
        foreach (['program_head', 'program_secretary'] as $role) {
            $viewer = new User(['role' => $role, 'program' => 'BSIS']); $viewer->id = 10;
            $activities = app(DashboardService::class)->getProgramHeadRecentActivity($viewer, ['start' => '2026-10-01', 'end' => '2026-10-31']);
            $this->assertCount(1, $activities);
            $this->assertSame('Prayer Meeting submission withdrawn', $activities[0]['title']);
            $this->assertSame('Secretary Mel withdrew the submission', $activities[0]['description']);
            $this->assertSame('withdrawn', $activities[0]['icon_type']);
        }
        $this->assertSame(0, \App\Models\AttendanceDraft::count());
    }

    public function test_coordinator_no_response_details_list_recorded_students_without_missing_or_responded_students(): void
    {
        $this->program('BSIS');
        $responded = $this->student('Responded');
        $noResponse = $this->student('Recorded no response');
        $missing = $this->student('Missing record');
        foreach ([[$responded, 'present'], [$responded, 'no_response'], [$noResponse, 'no_response'], [$noResponse, 'no_response']] as [$student, $status]) {
            AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'pm',
                'day' => '2026-10-28', 'reporting_day' => '2026-10-28', 'status' => $status]);
        }
        $details = app(CmCoordinatorMonitoringService::class)->getCourseDetails('BSIS', 1, ['year' => 2026, 'month' => 10, 'week' => 5]);
        $pm = collect($details['week_details'])->firstWhere('gathering_type', 'pm');
        $ws = collect($details['week_details'])->firstWhere('gathering_type', 'ws');
        $this->assertSame([$noResponse->id], $pm['no_response_students']->pluck('id')->all());
        $this->assertContains($missing->id, $pm['missing_students']->pluck('id')->all());
        $this->assertSame([], $ws['no_response_students']->all());
    }

    public function test_secretary_dashboard_prepends_recent_level_one_alerts_without_replacing_reminders(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id(); $table->integer('user_id'); $table->string('reminder_type');
            $table->string('program')->nullable(); $table->integer('year_level')->nullable();
            $table->string('title'); $table->string('status'); $table->dateTime('due_date');
            $table->string('description')->nullable(); $table->timestamps();
        });
        $this->program('BSIS'); $this->program('OLD', false);
        $user = new User(['role' => 'secretary', 'program' => 'BSIS', 'year_level' => 1]);
        $user->id = 1;
        foreach (['Oldest', 'Third', 'Second', 'Newest'] as $index => $name) {
            $student = $this->student($name);
            DB::table('escalations')->insert(['student_id' => $student->id, 'escalation_level' => 'Level 1',
                'no_response_count' => 1, 'updated_at' => '2026-10-'.(20 + $index).' 12:00:00']);
        }
        foreach ([['Inactive', 'BSIS', false, 1, 'Level 1'], ['Wrong program', 'ACT', true, 1, 'Level 1'],
            ['Inactive program', 'OLD', true, 1, 'Level 1'], ['Level two', 'BSIS', true, 1, 'Level 2'],
            ['Wrong year', 'BSIS', true, 2, 'Level 1']] as [$name, $program, $active, $year, $level]) {
            $student = $this->student($name, $program, $active);
            $student->update(['year_level' => $year]);
            DB::table('escalations')->insert(['student_id' => $student->id, 'escalation_level' => $level,
                'no_response_count' => 1, 'updated_at' => now()]);
        }
        foreach (range(1, 5) as $index) {
            \App\Models\Reminder::create(['user_id' => 1, 'reminder_type' => 'custom', 'title' => 'Task '.$index,
                'status' => 'upcoming', 'due_date' => now()->addDays($index)]);
        }
        \App\Models\Reminder::create(['user_id' => 2, 'reminder_type' => 'custom', 'title' => 'Another user',
            'status' => 'needs_action', 'due_date' => now()]);
        $method = new \ReflectionMethod(DashboardService::class, 'getSecretaryTasksAndReminders');
        $items = $method->invoke(app(DashboardService::class), $user);
        $this->assertCount(7, $items);
        $this->assertSame(['Newest', 'Second', 'Third', 'Task 1', 'Task 2', 'Task 3', 'Task 4'], array_column($items, 'title'));
        $this->assertSame('escalation', $items[0]['type']);
        $this->assertSame('Escalation Report', $items[0]['status_label']);
        $this->assertSame('custom', $items[3]['type']);
    }

    public function test_recent_approval_activity_names_the_actual_approver_for_each_viewer(): void
    {
        $this->program('BSIS');
        $student = $this->student('Student');
        $head = new User(['name' => 'Program Head', 'role' => 'program_head', 'program' => 'BSIS']);
        $head->id = 1;
        $secretary = new User(['name' => 'Program Secretary', 'role' => 'program_secretary', 'program' => 'BSIS']);
        $secretary->id = 2;
        DB::table('users')->insert(['id' => 2, 'name' => 'Program Secretary', 'role' => 'program_secretary',
            'program' => 'BSIS', 'email' => 'progsec@laverdad.edu.ph', 'password' => 'unused']);
        $record = AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'reporting_day' => '2026-10-28', 'batch_id' => 'approved-batch',
            'status' => 'present', 'validation_status' => 'validated', 'validated_by' => 2]);
        $service = app(DashboardService::class);
        $range = ['start' => '2026-10-01', 'end' => '2026-10-31'];
        $this->assertSame('Program Secretary approved the record', $service->getProgramHeadRecentActivity($head, $range)[0]['description']);
        $this->assertSame('You approved the record', $service->getProgramHeadRecentActivity($secretary, $range)[0]['description']);
        $record->update(['validated_by' => null]);
        $this->assertSame('The record was approved', $service->getProgramHeadRecentActivity($head, $range)[0]['description']);
        $record->update(['validated_by' => 999]);
        $this->assertSame('The record was approved', $service->getProgramHeadRecentActivity($head, $range)[0]['description']);
    }

    public function test_program_head_escalation_uses_only_submitted_gatherings_and_preserves_secretary_level(): void
    {
        $this->program('BSIS');
        $student = $this->student('Submitted responses');
        $head = new User(['role' => 'program_head', 'program' => 'BSIS']);
        $secretary = new User(['role' => 'secretary', 'program' => 'BSIS', 'year_level' => 1]);
        $escalation = \App\Models\Escalation::create(['student_id' => $student->id,
            'escalation_level' => 'Level 2', 'no_response_count' => 2]);
        \App\Models\AttendanceDraft::withoutEvents(fn () => \App\Models\AttendanceDraft::create([
            'user_id' => 1, 'gathering_type' => 'ws', 'day' => '2026-10-28', 'status' => 'draft',
            'parsed_data' => [['student_id' => $student->id, 'status' => 'no_response']],
        ]));
        $this->assertSame(0, \App\Models\Escalation::visibleTo($head)->count());
        $this->assertSame(1, \App\Models\Escalation::visibleTo($secretary)->count());
        $record = fn ($type, $day = '2026-10-28') => AttendanceRecord::create([
            'student_id' => $student->id, 'gathering_type' => $type, 'day' => $day,
            'status' => 'no_response', 'validation_status' => 'unvalidated',
        ]);
        $record('pm');
        $this->assertSame(0, \App\Models\Escalation::visibleTo($head)->count());
        $record('pm'); // Duplicate rows must not count as another gathering.
        $record('tg', '2026-10-21'); // Previous week must not contribute.
        $this->assertSame(0, \App\Models\Escalation::visibleTo($head)->count());
        $ws = $record('ws');
        $this->assertSame(1, \App\Models\Escalation::visibleTo($head)->count());
        // A third gathering in drafts can raise the secretary level but must
        // not hide the head's two submitted no-response gatherings.
        $escalation->update(['escalation_level' => 'Level 3', 'no_response_count' => 3]);
        $this->assertSame(1, \App\Models\Escalation::visibleTo($head)->count());
        $request = \Illuminate\Http\Request::create('/api/escalations');
        $request->setUserResolver(fn () => $head);
        $payload = app(\App\Http\Controllers\Escalations\EscalationController::class)->index($request)->getData(true);
        $this->assertSame('Level 2', $payload['data'][0]['escalation_level']);
        $this->assertSame(2, $payload['data'][0]['no_response_count']);
        $this->assertSame('Level 3', $escalation->fresh()->escalation_level);
        $tg = $record('tg');
        $this->assertSame(0, \App\Models\Escalation::visibleTo($head)->count());
        $tg->delete();
        $this->assertSame(1, \App\Models\Escalation::visibleTo($head)->count());
        $ws->delete();
        $this->assertSame(0, \App\Models\Escalation::visibleTo($head)->count());
    }

    public function test_deleting_drafts_refreshes_escalation_and_preserves_other_owners_drafts(): void
    {
        $this->app->instance(\App\Services\Escalations\WeeklyEscalationService::class, new \App\Services\Escalations\WeeklyEscalationService());
        $this->program('BSIS');
        $student = $this->student('Escalated student');
        $user = new User(['role' => 'secretary', 'program' => 'BSIS', 'year_level' => 1]);
        $user->id = 1;
        $drafts = collect(['pm', 'ws', 'tg'])->map(fn ($type) => \App\Models\AttendanceDraft::create([
            'user_id' => 1, 'gathering_type' => $type, 'day' => '2026-10-28', 'status' => 'draft',
            'parsed_data' => [['student_id' => $student->id, 'status' => 'no_response']],
        ]));
        $other = \App\Models\AttendanceDraft::create(['user_id' => 2, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'status' => 'draft', 'parsed_data' => []]);
        $this->assertSame(3, $student->escalation()->first()->no_response_count);

        $delete = function (array $ids) use ($user) {
            $request = \Illuminate\Http\Request::create('/api/attendance/drafts', 'DELETE', ['ids' => $ids]);
            $request->setUserResolver(fn () => $user);
            return app(\App\Http\Controllers\Attendance\AttendanceDraftController::class)->destroyMany($request)->getData(true);
        };
        $this->assertSame(1, $delete([$drafts[2]->id, $other->id])['deleted']);
        $this->assertSame('Level 2', $student->escalation()->first()->escalation_level);
        $this->assertNotNull($other->fresh());
        $delete([$drafts[1]->id]);
        $this->assertSame('Level 1', $student->escalation()->first()->escalation_level);
        $delete([$drafts[0]->id]);
        $this->assertSame('None', $student->escalation()->first()->escalation_level);
        $this->assertSame(0, $student->escalation()->first()->no_response_count);
        $this->assertSame(0, \App\Models\Escalation::visibleTo($user)->count());

        $record = AttendanceRecord::create(['student_id' => $student->id, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'status' => 'no_response']);
        $draft = \App\Models\AttendanceDraft::create(['user_id' => 1, 'gathering_type' => 'ws',
            'day' => '2026-10-28', 'status' => 'draft',
            'parsed_data' => [['student_id' => $student->id, 'status' => 'no_response']]]);
        $delete([$draft->id]);
        $this->assertSame('Level 1', $student->escalation()->first()->escalation_level);
        $record->delete();
        $this->assertSame('None', $student->escalation()->first()->escalation_level);
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
