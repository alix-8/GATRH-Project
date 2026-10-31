<?php

namespace Tests\Feature;

use App\Http\Controllers\Reminders\MonthlyExportController;
use App\Models\{AttendanceDraft, AttendanceRecord, MonthlyExportTask, Program, Reminder, Student, User};
use App\Services\Reminders\{MonthlyExportService, ReminderGenerationService};
use App\Services\ProgramHead\ProgramHeadAttendanceWorkbookService;
use App\Support\SystemTimezone;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonthlyExportReminderTest extends TestCase
{
    private MonthlyExportService $exports;
    private User $secretary;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-11-02 00:05:00', 'Asia/Manila'));
        $this->mock(\App\Services\Audit\AuditLogService::class)->shouldReceive('record', 'recordAction')->zeroOrMoreTimes();
        $this->mock(\App\Services\Escalations\WeeklyEscalationService::class)->shouldReceive('syncStudent', 'syncDraft')->zeroOrMoreTimes();
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('role'); $t->string('program')->nullable(); $t->integer('year_level')->nullable();
            $t->boolean('is_active')->default(true); $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('programs', function (Blueprint $t): void {
            $t->id(); $t->string('code'); $t->string('name'); $t->integer('max_year_level')->default(2);
            $t->boolean('is_active')->default(true); $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('program_user', function (Blueprint $t): void { $t->integer('program_id'); $t->integer('user_id'); });
        Schema::create('students', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('program'); $t->integer('year_level');
            $t->boolean('is_active')->default(true); $t->json('roster_history')->nullable(); $t->timestamps();
        });
        Schema::create('escalations', function (Blueprint $t): void {
            $t->id(); $t->integer('student_id'); $t->string('escalation_level')->default('None');
        });
        Schema::create('attendance_records', function (Blueprint $t): void {
            $t->id(); $t->integer('student_id'); $t->string('program')->nullable(); $t->integer('year_level')->nullable();
            $t->string('gathering_type'); $t->date('day'); $t->date('reporting_day')->nullable();
            $t->string('status'); $t->string('validation_status'); $t->string('venue')->nullable();
            $t->string('batch')->nullable(); $t->integer('submitted_by')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_drafts', function (Blueprint $t): void {
            $t->id(); $t->integer('user_id'); $t->string('gathering_type'); $t->date('day');
            $t->string('status'); $t->text('parsed_data')->nullable(); $t->text('raw_text')->nullable(); $t->timestamps();
        });
        Schema::create('reminders', function (Blueprint $t): void {
            $t->id(); $t->integer('user_id'); $t->string('reminder_type'); $t->string('title');
            $t->string('program')->nullable(); $t->integer('year_level')->nullable();
            $t->string('gathering_type')->nullable(); $t->date('gathering_date')->nullable();
            $t->integer('parent_reminder_id')->nullable(); $t->string('custom_type')->nullable();
            $t->text('description')->nullable(); $t->dateTime('due_date'); $t->string('status');
            $t->timestamp('completed_at')->nullable(); $t->timestamp('expired_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_30_000004_create_monthly_export_tasks.php'))->up();
        Program::create(['code' => 'BSIS', 'name' => 'Information Systems', 'max_year_level' => 2]);
        $this->secretary = $this->user('secretary');
        $this->student = Student::create(['name' => 'Student One', 'program' => 'BSIS', 'year_level' => 1]);
        $this->exports = app(MonthlyExportService::class);
    }

    protected function tearDown(): void
    {
        foreach (['monthly_export_tasks', 'reminders', 'attendance_drafts', 'attendance_records', 'escalations', 'students', 'program_user', 'programs', 'users'] as $table) Schema::dropIfExists($table);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Export User', 'email' => uniqid().'@example.com', 'password' => 'password',
            'role' => $role, 'program' => 'BSIS', 'year_level' => 1, 'is_active' => true,
        ]);
        $user->forceFill(['created_at' => Carbon::parse('2026-10-01', 'Asia/Manila')])->save();
        return $user;
    }

    private function task(?User $user = null): MonthlyExportTask
    {
        $user ??= $this->secretary;
        $this->exports->reconcile($user);
        return MonthlyExportTask::where('user_id', $user->id)->where('year_level', 1)->whereDate('month', '2026-10-01')->firstOrFail();
    }

    private function record(string $approval = 'unvalidated'): AttendanceRecord
    {
        return AttendanceRecord::create([
            'student_id' => $this->student->id, 'program' => 'BSIS', 'year_level' => 1,
            'gathering_type' => 'pm', 'day' => '2026-10-26', 'reporting_day' => '2026-10-28',
            'status' => 'present', 'validation_status' => $approval, 'venue' => 'Special', 'batch' => '08:00:00',
        ]);
    }

    private function download(MonthlyExportTask $task, ?User $user = null): string
    {
        $request = Request::create('/export', 'POST');
        $request->setUserResolver(fn () => $user ?? $this->secretary);
        $response = app(MonthlyExportController::class)->download($request, $task->reminder_id, $this->exports, app(ProgramHeadAttendanceWorkbookService::class));
        $this->assertFileExists($response->getFile()->getPathname());
        $this->assertSame('needs_action', $task->reminder->fresh()->status); // Generating a file alone cannot finish it.
        @unlink($response->getFile()->getPathname());
        return $response->headers->get('X-Monthly-Export-Token');
    }

    public function test_last_gathering_window_roles_catch_up_and_uniqueness(): void
    {
        $this->user('program_head'); $this->user('program_secretary');
        Carbon::setTestNow(Carbon::parse('2026-11-01 23:59:59', 'Asia/Manila'));
        $this->assertSame(0, $this->exports->reconcile());
        Carbon::setTestNow(Carbon::parse('2026-11-02 00:00:00', 'Asia/Manila'));
        $this->assertSame(5, $this->exports->reconcile()); // Secretary: 1 scope; Head and Program Secretary: 2 each.
        $this->assertSame(0, $this->exports->reconcile());
        $this->assertDatabaseCount('monthly_export_tasks', 5);
        $this->assertStringContainsString('October 2026', Reminder::firstOrFail()->title);
        Carbon::setTestNow(Carbon::parse('2027-01-05', 'Asia/Manila'));
        $this->assertSame(10, $this->exports->reconcile()); // November + December catch-up.
        $this->assertDatabaseCount('monthly_export_tasks', 15);
    }

    public function test_download_completion_is_personal_and_later_changes_reopen_the_same_reminder(): void
    {
        $record = $this->record();
        $task = $this->task(); $other = $this->task($this->user('secretary'));
        $id = $task->reminder_id;
        $token = $this->download($task);
        $this->assertTrue($this->exports->acknowledge($this->secretary, $task->fresh(), $token));
        $this->assertSame('completed', $task->reminder->fresh()->status);
        $this->assertSame('needs_action', $other->reminder->fresh()->status);
        app(ReminderGenerationService::class)->expireCompletedReminders();
        $this->assertNull($task->reminder->fresh()->expired_at);
        Carbon::setTestNow(SystemTimezone::now()->addMinute());
        $record->update(['batch' => '09:00:00']);
        $this->exports->refresh($task->fresh());
        $this->assertSame($id, $task->fresh()->reminder_id);
        $this->assertSame('needs_action', $task->reminder->fresh()->status);
        $this->assertStringContainsString('Records updated—export again.', $task->reminder->fresh()->description);
        $this->assertNotNull($task->fresh()->exported_at);
        app(ReminderGenerationService::class)->expireCompletedReminders();
        $this->assertNull($task->reminder->fresh()->expired_at);
    }

    public function test_approval_alone_finalizes_after_a_final_export_even_with_missing_attendance(): void
    {
        $record = $this->record(); $task = $this->task();
        $token = $this->download($task);
        $this->exports->acknowledge($this->secretary, $task->fresh(), $token);
        Carbon::setTestNow(SystemTimezone::now()->addMinute());
        $record->update(['validation_status' => 'validated']);
        $this->exports->refresh($task->fresh());
        $this->assertSame('needs_action', $task->reminder->fresh()->status); // One final approved export is required.
        $token = $this->download($task);
        $this->exports->acknowledge($this->secretary, $task->fresh(), $token);
        $this->assertTrue($task->fresh()->exported_approved);
        $this->assertGreaterThan(0, $this->exports->snapshot($task)['missing']);
        Carbon::setTestNow(SystemTimezone::now()->addMinute());
        $record->update(['venue' => 'Updated venue']);
        $this->exports->refresh($task->fresh());
        $this->assertSame('completed', $task->reminder->fresh()->status); // Still approved: final export stops reopening.
        $record->update(['validation_status' => 'unvalidated']);
        $record->update(['validation_status' => 'validated']);
        $this->exports->refresh($task->fresh());
        $this->assertSame('needs_action', $task->reminder->fresh()->status); // Revocation is remembered even after approval returns.
    }

    public function test_changes_during_download_do_not_complete_a_newer_version(): void
    {
        $record = $this->record(); $task = $this->task(); $token = $this->download($task);
        Carbon::setTestNow(SystemTimezone::now()->addMinute());
        $record->update(['batch' => '10:00:00']);
        $this->assertFalse($this->exports->acknowledge($this->secretary, $task->fresh(), $token));
        $this->assertSame('needs_action', $task->reminder->fresh()->status);
    }

    public function test_historical_students_drafts_and_submitted_rows_are_deduplicated(): void
    {
        $record = $this->record('validated');
        $this->student->update(['year_level' => 2, 'is_active' => false]);
        AttendanceDraft::create([
            'user_id' => $this->secretary->id, 'gathering_type' => 'pm', 'day' => '2026-10-28', 'status' => 'draft',
            'parsed_data' => [['student_id' => $this->student->id, 'status' => 'present', 'batch' => '07:00:00', 'venue' => 'Old draft']],
        ]);
        $snapshot = $this->exports->snapshot($this->task());
        $this->assertCount(1, $snapshot['students']);
        $this->assertCount(1, $snapshot['rows']);
        $this->assertSame($record->id, $snapshot['rows']->first()->id);
        $this->assertSame('Special', $snapshot['rows']->first()->venue);
    }

    public function test_other_users_cannot_export_or_acknowledge_the_reminder(): void
    {
        $task = $this->task(); $other = $this->user('secretary');
        $this->actingAs($other)->postJson('/api/reminders/'.$task->reminder_id.'/monthly-export')->assertForbidden();
        $this->actingAs($other)->postJson('/api/reminders/'.$task->reminder_id.'/monthly-export/complete', ['token' => str_repeat('x', 64)])->assertForbidden();
    }

    public function test_invalid_or_expired_confirmation_does_not_complete_the_reminder(): void
    {
        $task = $this->task(); $token = $this->download($task);
        Carbon::setTestNow(SystemTimezone::now()->addHours(2));
        $this->actingAs($this->secretary)->postJson('/api/reminders/'.$task->reminder_id.'/monthly-export/complete', ['token' => $token])->assertUnprocessable();
        $this->assertSame('needs_action', $task->reminder->fresh()->status);
    }

    public function test_even_an_approved_download_must_match_the_snapshot_before_finalization(): void
    {
        $record = $this->record('validated'); $task = $this->task(); $token = $this->download($task);
        Carbon::setTestNow(SystemTimezone::now()->addMinute());
        $record->update(['batch' => '11:00:00']);
        $this->assertFalse($this->exports->acknowledge($this->secretary, $task->fresh(), $token));
        $this->assertFalse($task->fresh()->exported_approved);
        $this->assertSame('needs_action', $task->reminder->fresh()->status);
    }

    public function test_secretary_submission_reminder_completes_on_submission_even_without_student_responses(): void
    {
        $reminder = Reminder::create([
            'user_id' => $this->secretary->id, 'reminder_type' => 'submission_countdown',
            'gathering_type' => 'pm', 'gathering_date' => '2026-10-28', 'program' => 'BSIS', 'year_level' => 1,
            'title' => 'Submit attendance', 'due_date' => '2026-10-30', 'status' => 'needs_action',
        ]);
        $draft = AttendanceDraft::create([
            'user_id' => $this->secretary->id, 'gathering_type' => 'pm', 'day' => '2026-10-28', 'status' => 'draft',
            'parsed_data' => [['student_id' => $this->student->id, 'status' => 'present']],
        ]);
        $generation = app(ReminderGenerationService::class);
        $generation->refreshSubmissionProgress($this->secretary->id);
        $this->assertSame('needs_action', $reminder->fresh()->status);
        $record = $this->record();
        $record->update(['submitted_by' => $this->secretary->id, 'status' => 'no_response']);
        $generation->refreshSubmissionProgress($this->secretary->id);
        $this->assertSame('completed', $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->completed_at);
        $this->assertSame('unvalidated', $record->fresh()->validation_status);
        $record->delete(); // An unsubmitted record returns the submission task to Needs Action.
        $generation->refreshSubmissionProgress($this->secretary->id);
        $this->assertSame('needs_action', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->completed_at);
    }

    public function test_export_confirmation_preview_shows_scope_and_live_counts_without_completing(): void
    {
        $this->record();
        $task = $this->task();
        $path = '/api/reminders/'.$task->reminder_id.'/monthly-export/preview';
        $this->actingAs($this->secretary)->getJson($path)->assertOk()
            ->assertJsonPath('program', 'BSIS')->assertJsonPath('year_level', 1)
            ->assertJsonPath('month', 'October 2026')
            ->assertJsonPath('coverage', 'All reporting weeks · PM, WS, TG')
            ->assertJsonPath('students_included', 1)
            ->assertJsonPath('pending_entries', 1)->assertJsonPath('missing_entries', 14);
        $this->assertSame('needs_action', $task->reminder->fresh()->status);
        $this->assertNull($task->fresh()->download_token);
        $other = $this->user('secretary');
        $this->actingAs($other)->getJson($path)->assertForbidden();
    }

    public function test_local_test_reminder_uses_real_scope_data_without_changing_normal_eligibility(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30', 'Asia/Manila'));
        $record = $this->record();
        $arguments = ['--month' => '2026-10', '--user' => $this->secretary->email, '--program' => 'BSIS', '--year' => 1];
        $this->assertSame(0, $this->exports->reconcile($this->secretary));
        $this->artisan('reminders:monthly-export-test', $arguments)->assertSuccessful();
        $this->artisan('reminders:monthly-export-test', $arguments)->assertSuccessful();
        $this->assertDatabaseCount('monthly_export_tasks', 1);
        $this->assertDatabaseCount('reminders', 1);
        $task = MonthlyExportTask::firstOrFail();
        $this->assertStringContainsString('(Local test)', $task->reminder->title);
        $this->assertSame($record->id, $this->exports->snapshot($task)['rows']->first()->id);
        $this->assertSame('2026-11-02', $this->exports->eligibleAt(Carbon::parse('2026-10-01'))->toDateString());
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertSame('2026-10-26', $record->fresh()->day->toDateString());
    }

    public function test_local_test_reminder_rejects_a_scope_not_assigned_to_the_account(): void
    {
        $this->artisan('reminders:monthly-export-test', [
            '--month' => '2026-10', '--user' => $this->secretary->email, '--program' => 'BSIS', '--year' => 2,
        ])->assertFailed();
        $this->assertDatabaseCount('monthly_export_tasks', 0);
    }

    public function test_api_exposes_download_token_for_the_frontend_and_completion_requires_export(): void
    {
        $user = $this->user('program_secretary');
        $task = $this->task($user);
        $response = $this->actingAs($user)->withHeader('Origin', 'http://localhost:5173')
            ->post('/api/reminders/'.$task->reminder_id.'/monthly-export');
        $response->assertOk();
        $this->assertStringContainsString('X-Monthly-Export-Token', $response->headers->get('Access-Control-Expose-Headers'));
        $token = $response->headers->get('X-Monthly-Export-Token');
        $this->assertNotEmpty($token);
        @unlink($response->baseResponse->getFile()->getPathname());
        $this->postJson('/api/reminders/'.$task->reminder_id.'/monthly-export/complete', ['token' => $token])->assertOk();
        $this->getJson('/api/reminders')->assertOk()->assertJsonPath('summary.completed', 1);
        $this->assertSame('completed', $task->reminder->fresh()->status);
        $otherTask = MonthlyExportTask::where('user_id', $user->id)->where('year_level', 2)->firstOrFail();
        $this->patchJson('/api/reminders/'.$otherTask->reminder_id, ['status' => 'completed'])->assertUnprocessable();
        $this->deleteJson('/api/reminders/'.$otherTask->reminder_id)->assertUnprocessable();
        $this->assertSame('needs_action', $otherTask->reminder->fresh()->status);
    }
}
