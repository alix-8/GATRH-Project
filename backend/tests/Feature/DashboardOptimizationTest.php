<?php

namespace Tests\Feature;

use App\Models\{AttendanceDraft, AttendanceRecord, AuditLog, Program, Student, User};
use App\Services\Admin\AdminDashboardService;
use App\Services\Audit\AuditLogService;
use App\Services\Dashboard\{DashboardService, SecretaryRecentAttendanceQuery};
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class DashboardOptimizationTest extends TestCase
{
    private User $secretary;
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->mock(AuditLogService::class)->shouldReceive('record', 'recordAction')->zeroOrMoreTimes();
        $this->mock(\App\Services\Escalations\WeeklyEscalationService::class)->shouldReceive('syncStudent', 'syncDraft')->zeroOrMoreTimes();
        Schema::create('programs', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->boolean('is_active')->default(true);
            $t->integer('max_year_level')->default(4); $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('program'); $t->string('year_level');
            $t->boolean('is_active')->default(true); $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->string('role');
            $t->string('program')->nullable(); $t->string('year_level')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('program_user', function (Blueprint $t) { $t->integer('program_id'); $t->integer('user_id'); });
        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id(); $t->integer('student_id'); $t->string('gathering_type'); $t->date('day'); $t->date('reporting_day')->nullable();
            $t->string('program')->nullable(); $t->integer('year_level')->nullable(); $t->string('batch_id')->nullable();
            $t->string('batch')->nullable(); $t->string('venue')->nullable(); $t->integer('submitted_by')->nullable();
            $t->integer('validated_by')->nullable(); $t->string('status')->default('present');
            $t->string('validation_status')->default('unvalidated'); $t->timestamps();
        });
        Schema::create('attendance_drafts', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->string('gathering_type'); $t->date('day'); $t->string('status');
            $t->json('parsed_data')->nullable(); $t->integer('matched_count')->nullable(); $t->text('raw_text')->nullable();
            $t->timestamp('unsubmitted_at')->nullable(); $t->timestamps();
        });
        Schema::create('escalations', function (Blueprint $t) {
            $t->id(); $t->integer('student_id'); $t->string('escalation_level'); $t->integer('no_response_count'); $t->timestamps();
        });
        Schema::create('reminders', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->string('reminder_type'); $t->string('title'); $t->text('description')->nullable();
            $t->string('program')->nullable(); $t->integer('year_level')->nullable(); $t->string('status'); $t->dateTime('due_date'); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->integer('actor_id'); $t->string('action'); $t->string('target_table'); $t->string('target_id');
            $t->string('result'); $t->text('details')->nullable(); $t->timestamps();
        });
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems']);
        Program::create(['code' => 'ACT', 'name' => 'Computer Technology']);
        Program::create(['code' => 'OLD', 'name' => 'Inactive', 'is_active' => false]);
        $this->secretary = User::create(['name' => 'Secretary', 'email' => 'sec@laverdad.edu.ph', 'password' => 'password',
            'role' => 'secretary', 'program' => 'BSIS', 'year_level' => 2]);
        $this->students = array_map(fn ($i) => $this->student('Student '.$i), [1, 2, 3]);
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, array $fields = []): Student
    {
        return Student::create(array_merge(['name' => $name, 'program' => 'BSIS', 'year_level' => 2], $fields));
    }

    private function record(Student $student, array $fields = []): AttendanceRecord
    {
        return AttendanceRecord::create(array_merge(['student_id' => $student->id, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'reporting_day' => '2026-10-28', 'batch_id' => 'current-pm',
            'venue' => 'Chapel', 'status' => 'present', 'validation_status' => 'unvalidated', 'submitted_by' => $this->secretary->id], $fields));
    }

    public function test_secretary_reuses_data_without_changing_any_section_results(): void
    {
        $inactive = $this->student('Inactive', ['is_active' => false]);
        $otherYear = $this->student('Other year', ['year_level' => 3]);
        $this->record($this->students[0]);
        $this->record($this->students[1], ['status' => 'no_response']);
        $this->record($this->students[2], ['status' => 'excused']);
        $this->record($inactive); // Existing history must remain available.
        $this->record($otherYear); // Must not enter this secretary's totals.
        $this->record($this->students[0], ['gathering_type' => 'ws', 'day' => '2026-10-31', 'reporting_day' => '2026-10-31', 'batch_id' => 'ws', 'status' => 'no_response']);
        foreach (['2026-10-28', '2026-10-21'] as $day) {
            AttendanceDraft::create(['user_id' => $this->secretary->id, 'gathering_type' => 'pm', 'day' => $day, 'status' => $day === '2026-10-28' ? 'draft' : 'pending',
                'parsed_data' => [['student_id' => $this->students[1]->id, 'status' => 'present'], ['student_id' => $this->students[2]->id, 'status' => 'no_response']]]);
        }
        $service = app(DashboardService::class);
        $range = ['start' => Carbon::parse('2026-10-28'), 'end' => Carbon::parse('2026-11-03')];
        $month = Carbon::parse('2026-10-01');
        $expectedKpis = (new \ReflectionMethod($service, 'getSecretaryKpis'))->invoke($service, $this->secretary, $range);
        $expectedMonthly = (new \ReflectionMethod($service, 'getSecretaryMonthlySummary'))->invoke($service, $this->secretary, $month);
        $expectedGatherings = $service->getMonthlyGatheringData($this->secretary, 10, 2026);
        foreach (['week', 'last_week'] as $period) {
            $chartRange = $period === 'week' ? $range : ['start' => Carbon::parse('2026-10-21'), 'end' => Carbon::parse('2026-10-27')];
            $expectedChart = $service->getAttendanceChartData($this->secretary, $period, $chartRange)['series'];
            DB::enableQueryLog(); DB::flushQueryLog();
            $payload = $service->getGeneralDashboardPayload($this->secretary, ['year' => 2026, 'month' => 10, 'attendance_period' => $period]);
            $queries = collect(DB::getQueryLog())->pluck('query'); DB::disableQueryLog();
            $this->assertSame($expectedKpis, $payload['kpis']);
            $this->assertSame($expectedMonthly, $payload['monthly_summary']);
            $this->assertSame($expectedGatherings, $payload['monthly_gathering']);
            $this->assertSame($expectedChart, $payload['attendance_overview']);
            $this->assertSame(3, $payload['kpis']['total_students']);
            $this->assertCount(1, $queries->filter(fn ($sql) => str_contains($sql, 'select count(*) as "aggregate" from "students"')), $queries->implode("\n"));
            $this->assertCount(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "attendance_drafts"')), $queries->implode("\n"));
        }
        $this->record($this->students[0], ['gathering_type' => 'tg', 'batch_id' => 'new-tg']);
        $fresh = $service->getGeneralDashboardPayload($this->secretary, ['year' => 2026, 'month' => 10]);
        $this->assertSame($expectedKpis['week_respondents'] + 1, $fresh['kpis']['week_respondents']);
    }

    public function test_recent_selection_never_cuts_a_student_batch_or_changes_legacy_period_resolution(): void
    {
        foreach (range(4, 22) as $index) $this->students[] = $this->student('Student '.$index);
        foreach ($this->students as $index => $student) {
            $this->record($student, ['validation_status' => $index === 21 ? 'unvalidated' : 'validated',
                'status' => $index === 21 ? 'no_response' : 'present']);
        }
        foreach (range(1, 12) as $week) {
            $day = Carbon::parse('2026-10-28')->subWeeks($week)->toDateString();
            $this->record($this->students[0], ['batch_id' => 'older-'.$week, 'day' => $day, 'reporting_day' => $day]);
        }
        $other = $this->student('Other program', ['program' => 'ACT']);
        $this->record($other, ['batch_id' => 'not-accessible', 'reporting_day' => '2026-10-31']);
        $rows = app(SecretaryRecentAttendanceQuery::class)->records($this->secretary);
        $this->assertSame(22, $rows->where('batch_id', 'current-pm')->count());
        $this->assertCount(5, $rows->groupBy('batch_id'));
        $this->assertFalse($rows->contains('batch_id', 'not-accessible'));
        $recent = app(DashboardService::class)->getRecentRecords($this->secretary);
        $this->assertSame('21/22 students', $recent[0]['students']);
        $this->assertSame('pending', $recent[0]['status']);
        $this->record($this->students[0], ['batch_id' => 'legacy-special', 'reporting_day' => null, 'day' => '2026-10-01', 'venue' => 'Special']);
        $this->record($this->students[1], ['batch_id' => 'legacy-special', 'reporting_day' => null, 'day' => '2026-10-30']);
        $legacy = collect(app(DashboardService::class)->getRecentRecords($this->secretary))->firstWhere('batch_id', 'legacy-special');
        $this->assertSame('2026-10-28', $legacy['day_iso']);
        $first = $this->record($this->students[0], ['batch_id' => null, 'reporting_day' => null, 'day' => '2026-10-29']);
        $second = $this->record($this->students[1], ['batch_id' => '', 'reporting_day' => null, 'day' => '2026-10-29']);
        $rows = app(SecretaryRecentAttendanceQuery::class)->records($this->secretary);
        $this->assertTrue($rows->contains('id', $first->id));
        $this->assertTrue($rows->contains('id', $second->id));
    }

    public function test_program_head_sections_share_one_attendance_read(): void
    {
        $head = User::create(['name' => 'Head', 'email' => 'head@laverdad.edu.ph', 'password' => 'password', 'role' => 'program_head', 'program' => 'BSIS']);
        $this->record($this->students[0]);
        $this->record($this->students[1], ['validation_status' => 'validated', 'validated_by' => $head->id]);
        $service = app(DashboardService::class);
        $range = $service->resolveDateRange('all');
        $summary = $service->getProgramHeadSummary($head, $range);
        $pending = $service->getProgramHeadPendingList($head, $range);
        $activities = $service->getProgramHeadRecentActivity($head, $range);
        DB::enableQueryLog(); DB::flushQueryLog();
        $payload = $service->getProgramHeadDashboardPayload($head, ['week' => 'all', 'month' => 10, 'year' => 2026]);
        $queries = collect(DB::getQueryLog())->pluck('query'); DB::disableQueryLog();
        $this->assertSame($summary, $payload['summary']);
        $this->assertSame($pending, $payload['pending_list']);
        $this->assertSame($activities, $payload['recent_records']);
        $this->assertArrayNotHasKey('calendar_marks', $payload);
        $this->assertCount(1, $queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "attendance_records"')));
    }

    public function test_withdrawal_history_scans_past_other_programs_before_limiting_results(): void
    {
        $head = new User(['role' => 'program_head', 'program' => 'BSIS']); $head->id = 99;
        foreach (range(1, 110) as $index) {
            AuditLog::create(['actor_id' => $this->secretary->id, 'action' => 'Unsubmitted attendance submission',
                'target_table' => 'attendance_records', 'target_id' => 'batch-'.$index, 'result' => 'success',
                'details' => json_encode(['submissions' => [['program' => $index <= 5 ? 'BSIS' : 'ACT', 'year_level' => 2,
                    'gathering_type' => 'pm', 'reporting_day' => '2026-10-28']]])]);
        }
        $activities = app(DashboardService::class)->getProgramHeadRecentActivity($head,
            ['start' => '2026-10-01', 'end' => '2026-10-31']);
        $this->assertCount(4, $activities);
        $this->assertSame(['withdrawal-5-0', 'withdrawal-4-0', 'withdrawal-3-0', 'withdrawal-2-0'], array_column($activities, 'id'));
    }

    public function test_admin_aggregates_keep_total_and_active_counts_separate(): void
    {
        User::create(['name' => 'Inactive', 'email' => 'inactive@laverdad.edu.ph', 'password' => 'password', 'role' => 'secretary', 'is_active' => false]);
        $this->student('Inactive program student', ['program' => 'OLD']);
        $summary = app(AdminDashboardService::class)->getPayload()['summary'];
        $this->assertSame(['user_accounts' => 2, 'active_users' => 1, 'students' => 3, 'programs' => 3, 'active_programs' => 2], $summary);
    }
}
