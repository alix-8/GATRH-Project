<?php

namespace Tests\Feature;

use App\Models\{AttendanceDraft, AttendanceRecord, Escalation, Program, Student, User};
use App\Services\Dashboard\DashboardService;
use App\Services\Reminders\{MonthlyExportService, ReminderCountdownService, ReminderGenerationService};
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecretaryEscalationReminderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 12:00:00');

        Schema::create('programs', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('program'); $table->string('year_level');
            $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password');
            $table->string('role'); $table->string('program')->nullable(); $table->string('year_level')->nullable();
            $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('program_user', function (Blueprint $table) {
            $table->integer('program_id'); $table->integer('user_id');
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id(); $table->integer('student_id'); $table->string('gathering_type'); $table->date('day');
            $table->string('status'); $table->integer('submitted_by'); $table->string('validation_status')->default('unvalidated');
            $table->timestamps();
        });
        Schema::create('attendance_drafts', function (Blueprint $table) {
            $table->id(); $table->integer('user_id'); $table->string('gathering_type'); $table->date('day');
            $table->string('status'); $table->json('parsed_data')->nullable(); $table->timestamps();
        });
        Schema::create('escalations', function (Blueprint $table) {
            $table->id(); $table->integer('student_id'); $table->integer('no_response_count');
            $table->string('escalation_level'); $table->timestamps();
        });
        Schema::create('reminders', function (Blueprint $table) {
            $table->id(); $table->integer('user_id'); $table->string('reminder_type');
            $table->string('gathering_type')->nullable(); $table->date('gathering_date')->nullable();
            $table->string('program')->nullable(); $table->integer('year_level')->nullable();
            $table->string('title'); $table->string('status'); $table->dateTime('due_date'); $table->timestamps();
        });
        Schema::create('monthly_export_tasks', function (Blueprint $table) {
            $table->id(); $table->integer('user_id'); $table->integer('reminder_id')->nullable();
        });

        $monthlyExports = $this->mock(MonthlyExportService::class);
        $monthlyExports->shouldReceive('reconcile')->zeroOrMoreTimes()->andReturn(0);
        $monthlyExports->shouldReceive('revokeFinalization')->zeroOrMoreTimes();
        $monthlyExports->shouldReceive('scopes')->zeroOrMoreTimes()->andReturn([]);
        $generation = $this->mock(ReminderGenerationService::class);
        $generation->shouldReceive('generateWeeklyGatheringReminders')->zeroOrMoreTimes()->andReturn(0);
        $generation->shouldReceive('spawnSubmissionReminders', 'refreshSubmissionProgress')->zeroOrMoreTimes();
        $countdown = $this->mock(ReminderCountdownService::class);
        $countdown->shouldReceive('statusSetter')->zeroOrMoreTimes();
        $countdown->shouldReceive('reminderCountdownFor')->zeroOrMoreTimes()->andReturn([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['monthly_export_tasks', 'reminders', 'escalations', 'attendance_drafts', 'attendance_records', 'program_user', 'users', 'students', 'programs'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_secretary_level_one_follow_up_remains_visible_after_more_no_response_gatherings(): void
    {
        Program::withoutEvents(fn () => Program::create(['code' => 'BSIS', 'is_active' => true]));
        $secretary = User::withoutEvents(fn () => User::create([
            'name' => 'Secretary', 'email' => 'secretary@example.com', 'password' => 'password',
            'role' => 'secretary', 'program' => 'BSIS', 'year_level' => 3,
        ]));
        $head = User::withoutEvents(fn () => User::create([
            'name' => 'Program Head', 'email' => 'head@example.com', 'password' => 'password',
            'role' => 'program_head', 'program' => 'BSIS',
        ]));
        $coordinator = User::withoutEvents(fn () => User::create([
            'name' => 'Coordinator', 'email' => 'coordinator@example.com', 'password' => 'password',
            'role' => 'cm_coordinator',
        ]));
        $student = Student::withoutEvents(fn () => Student::create([
            'name' => 'Student', 'program' => 'BSIS', 'year_level' => 3,
        ]));

        foreach ([['pm', '2026-10-28'], ['ws', '2026-10-31'], ['tg', '2026-10-31']] as $index => [$type, $day]) {
            $records[] = AttendanceRecord::create([
                'student_id' => $student->id, 'gathering_type' => $type, 'day' => $day,
                'status' => 'no_response', 'submitted_by' => $secretary->id,
            ]);

            $currentLevel = 'Level '.($index + 1);
            $this->assertSame($currentLevel, Escalation::where('student_id', $student->id)->value('escalation_level'));
            $payload = $this->actingAs($secretary)->getJson('/api/reminders')->assertOk()->json();
            $this->assertSame(1, $payload['summary']['escalation_count']);
            $this->assertCount(1, $payload['escalations']);
            $this->assertSame('Level 1', $payload['escalations'][0]['escalation_level']);
            $this->assertSame('Level 1', $payload['escalations'][0]['alert_level']);
            $this->assertSame($index + 1, $payload['escalations'][0]['no_response_count']);

            foreach ([[$head, 'Level 2', $index >= 1], [$coordinator, 'Level 3', $index >= 2]] as [$recipient, $recipientLevel, $visible]) {
                $rolePayload = $this->actingAs($recipient)->getJson('/api/reminders')->assertOk()->json();
                $this->assertSame($visible ? 1 : 0, $rolePayload['summary']['escalation_count']);
                $this->assertCount($visible ? 1 : 0, $rolePayload['escalations']);
                if ($visible) {
                    $this->assertSame($recipientLevel, $rolePayload['escalations'][0]['escalation_level']);
                    $this->assertSame($index + 1, $rolePayload['escalations'][0]['no_response_count']);
                }
            }
        }

        $dashboardItems = (new \ReflectionMethod(DashboardService::class, 'getSecretaryTasksAndReminders'))
            ->invoke(app(DashboardService::class), $secretary);
        $this->assertSame('escalation', $dashboardItems[0]['type']);
        $this->assertStringContainsString('Level 1 follow up', $dashboardItems[0]['description']);

        foreach ($records as $record) {
            $record->update(['status' => 'present']);
        }
        $this->assertSame('None', Escalation::where('student_id', $student->id)->value('escalation_level'));
        $payload = $this->actingAs($secretary)->getJson('/api/reminders')->assertOk()->json();
        $this->assertSame(0, $payload['summary']['escalation_count']);
        $this->assertSame([], $payload['escalations']);
        foreach ([$head, $coordinator] as $recipient) {
            $rolePayload = $this->actingAs($recipient)->getJson('/api/reminders')->assertOk()->json();
            $this->assertSame(0, $rolePayload['summary']['escalation_count']);
        }
    }

    public function test_two_imported_drafts_alert_the_program_head_at_level_two(): void
    {
        Program::withoutEvents(fn () => Program::create(['code' => 'BSIS', 'is_active' => true]));
        $secretary = User::withoutEvents(fn () => User::create([
            'name' => 'Secretary', 'email' => 'secretary@example.com', 'password' => 'password',
            'role' => 'secretary', 'program' => 'BSIS', 'year_level' => 3,
        ]));
        $head = User::withoutEvents(fn () => User::create([
            'name' => 'Program Head', 'email' => 'head@example.com', 'password' => 'password',
            'role' => 'program_head', 'program' => 'BSIS',
        ]));
        $student = Student::withoutEvents(fn () => Student::create([
            'name' => 'Student', 'program' => 'BSIS', 'year_level' => 3,
        ]));

        foreach (['pm', 'ws'] as $type) {
            AttendanceDraft::create([
                'user_id' => $secretary->id, 'gathering_type' => $type,
                'day' => '2026-10-28', 'status' => 'draft',
                'parsed_data' => [['student_id' => $student->id, 'status' => 'no_response']],
            ]);
        }

        $this->assertSame('Level 2', Escalation::where('student_id', $student->id)->value('escalation_level'));
        $secretaryAlerts = $this->actingAs($secretary)->getJson('/api/reminders')->assertOk()->json('escalations');
        $headAlerts = $this->actingAs($head)->getJson('/api/reminders')->assertOk()->json('escalations');
        $this->assertCount(1, $secretaryAlerts);
        $this->assertCount(1, $headAlerts);
        $this->assertSame('Level 1', $secretaryAlerts[0]['escalation_level']);
        $this->assertSame('Level 2', $headAlerts[0]['escalation_level']);
        $history = $this->actingAs($head)
            ->getJson("/api/students/{$student->id}/escalation-evidence")
            ->assertOk()->json('history');
        $this->assertCount(2, $history);
        $this->assertSame(['draft', 'draft'], array_column($history, 'source'));
    }
}

