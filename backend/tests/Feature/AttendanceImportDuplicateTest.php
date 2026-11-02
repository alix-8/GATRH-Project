<?php

namespace Tests\Feature;

use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceDraftController;
use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\AttendanceParsingService;
use App\Services\Audit\AuditLogService;
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
        $this->mock(\App\Services\Escalations\WeeklyEscalationService::class)
            ->shouldReceive('syncDraft', 'syncStudent')->zeroOrMoreTimes();
        $this->mock(AuditLogService::class)->shouldReceive('record', 'recordAction')->zeroOrMoreTimes();

        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('roster_history')->nullable();
        });
        Schema::create('program_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('program_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('program');
            $table->integer('year_level');
            $table->boolean('is_active')->default(true); $table->string('inactive_reason')->nullable(); $table->json('roster_history')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Secretary');
            $table->string('role')->default('secretary');
            $table->string('program');
            $table->integer('year_level');
        });
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('gathering_type');
            $table->date('day');
            $table->date('reporting_day')->nullable();
            $table->string('batch_id')->nullable();
            $table->string('program')->nullable();
            $table->integer('year_level')->nullable();
            $table->string('status')->default('present');
            $table->string('batch')->nullable();
            $table->string('venue')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('unsubmitted_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->string('validation_status');
            $table->timestamps();
        });
        Schema::create('attendance_drafts', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('unsubmitted_at')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('gathering_type');
            $table->string('status');
            $table->date('day');
            $table->text('raw_text')->nullable();
            $table->text('parsed_data')->nullable();
            $table->integer('total_detected')->default(0);
            $table->integer('matched_count')->default(0);
            $table->timestamps();
        });
        Student::create(['name' => 'Juan Dela Cruz', 'program' => 'BSIS', 'year_level' => 1]);
        \Illuminate\Support\Facades\DB::table('users')->insert(['id' => 1, 'program' => 'BSIS', 'year_level' => 1]);
        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('reminder_type');
            $table->string('gathering_type');
            $table->date('gathering_date');
        });
        Schema::create('escalations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('escalation_level')->default('None');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['program_user', 'programs', 'escalations', 'reminders', 'attendance_drafts', 'attendance_records', 'users', 'students'] as $table) {
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

    public function test_special_venue_accepts_past_dates_outside_each_gathering_range(): void
    {
        $service = app(AttendanceParsingService::class);
        foreach (['pm', 'ws', 'tg'] as $type) {
            $result = $service->buildRecordFromLine(
                '1. Juan Dela Cruz | 11:45PM |  sPeCiAl  | 2026-10-01',
                'BSIS', 1, '2026-10-28', $type
            );
            $this->assertSame([], $result['format_errors']);
            $this->assertSame('2026-10-01', $result['record']['day']);
            $this->assertSame('23:45:00', $result['record']['batch']);
        }

        $parsed = $service->parseBatchText(
            "1. Juan Dela Cruz\nPM: 2026-10-01 | 11:45PM | Special\nWS: 2026-10-02 | 1AM | special\nTG: 2026-10-03 | 13:30 | SPECIAL",
            'BSIS', 1
        );
        $this->assertSame([], $parsed['format_errors']);
        foreach (['pm' => '2026-10-01', 'ws' => '2026-10-02', 'tg' => '2026-10-03'] as $type => $date) {
            $this->assertSame($date, $parsed['records'][$type][0]['day']);
        }
    }

    public function test_special_dates_still_reject_future_and_invalid_dates_and_normal_venues_keep_range_checks(): void
    {
        $service = app(AttendanceParsingService::class);
        $start = Carbon::parse('2026-10-28');
        $end = Carbon::parse('2026-10-30');
        foreach (['2026-11-01', '2026-02-30', 'bad-date'] as $date) {
            $this->assertFalse($service->resolveWeekdayToDate($date, $start, $end, 'Special')['success']);
        }
        foreach (['Main Hall', 'Special Hall'] as $venue) {
            $this->assertFalse($service->resolveWeekdayToDate('2026-10-01', $start, $end, $venue)['success']);
        }
        $this->assertSame('2026-10-26', $service->resolveWeekdayToDate('Monday', $start, $end, 'Special')['date']);
        $this->assertFalse($service->resolveWeekdayToDate('Sunday', $start, $end, 'Special')['success']);

        foreach (['pm', 'ws', 'tg'] as $type) {
            $individual = $service->buildRecordFromLine(
                '1. Juan Dela Cruz | 11PM | Special | 2026-11-01',
                'BSIS', 1, '2026-10-28', $type
            );
            $this->assertStringContainsString('Future dates are not allowed', $individual['format_errors'][0]['reason']);
        }
        $weekly = $service->parseBatchText(
            "1. Juan Dela Cruz\nPM: 2026-11-01 | 11PM | Special\nWS: 2026-11-01 | 1AM | Special\nTG: 2026-11-01 | 1PM | Special",
            'BSIS', 1
        );
        $this->assertCount(3, $weekly['format_errors']);
    }

    public function test_special_dates_are_saved_by_individual_and_weekly_import(): void
    {
        foreach ([
            'parseText' => '1. Juan Dela Cruz | 11PM | Special | 2026-10-01',
            'parseBatchText' => "1. Juan Dela Cruz\nPM: 2026-10-01 | 11PM | Special\nWS: 2026-10-02 | 1AM | Special\nTG: 2026-10-03 | 1PM | Special",
        ] as $method => $text) {
            AttendanceDraft::query()->delete();
            $request = Request::create('/attendance/import', 'POST', [
                'raw_text' => $text, 'gathering_type' => 'pm', 'confirm_blank' => true,
            ]);
            $request->setUserResolver(fn () => $this->secretary());
            $response = app(AttendanceController::class)->{$method}($request);
            $this->assertSame(200, $response->getStatusCode(), $response->getContent());
            $draft = AttendanceDraft::where('gathering_type', 'pm')->firstOrFail();
            $this->assertSame('2026-10-01', $draft->parsed_data[0]['day']);
        }
    }

    public function test_edit_accepts_special_past_dates_and_rejects_future_or_normal_out_of_range_dates(): void
    {
        $audit = $this->mock(AuditLogService::class);
        $audit->shouldReceive('recordAction')->times(3);
        $student = Student::firstOrFail();
        foreach (['pm', 'ws', 'tg'] as $type) {
            $draft = AttendanceDraft::create([
                'user_id' => 1, 'gathering_type' => $type, 'day' => '2026-10-28',
                'raw_text' => '', 'status' => 'draft',
                'parsed_data' => [['student_id' => $student->id, 'student_name' => $student->name]],
            ]);
            foreach ([
                ['Special', '2026-10-01', 200],
                ['Special', '2026-11-01', 422],
                ['Main Hall', '2026-10-01', 422],
            ] as [$venue, $date, $status]) {
                $request = Request::create('/attendance/drafts/'.$draft->id, 'PATCH', [
                    'records' => [[
                        'student_id' => $student->id, 'day' => $date, 'day_provided' => true,
                        'batch' => '11PM', 'venue' => $venue,
                    ]],
                ]);
                $request->setUserResolver(fn () => $this->secretary());
                $response = app(AttendanceDraftController::class)->update($request, $draft->id, app(AttendanceParsingService::class), $audit);
                $this->assertSame($status, $response->getStatusCode(), $response->getContent());
                $this->assertSame('2026-10-01', $draft->fresh()->parsed_data[0]['day']);
            }
        }
    }

    public function test_mixed_special_and_regular_dates_survive_submit_review_unsubmit_and_edit(): void
    {
        $this->assertMixedDatesSurviveLifecycle(false);
    }

    public function test_weekly_mixed_special_and_regular_dates_survive_the_same_lifecycle(): void
    {
        $this->assertMixedDatesSurviveLifecycle(true);
    }

    private function assertMixedDatesSurviveLifecycle(bool $weekly): void
    {
        Student::firstOrFail()->update(['name' => 'Ariate Angelene']);
        Student::create(['name' => 'JR Balmaceda', 'program' => 'BSIS', 'year_level' => 1]);
        $controller = app(AttendanceController::class);
        $requestFor = function (array $input, string $method = 'POST'): Request {
            $request = Request::create('/attendance', $method, $input);
            $request->setUserResolver(fn () => $this->secretary());
            return $request;
        };

        // Put the special row first, as in the reported regression.
        $importMethod = $weekly ? 'parseBatchText' : 'parseText';
        $import = $controller->{$importMethod}($requestFor([
            'raw_text' => $weekly
                ? "1. Ariate Angelene\nPM: Monday | 8AM | Special\n2. JR Balmaceda\nPM: Wed | 9AM | Main Hall"
                : "1. Ariate Angelene | 8AM | Special | Monday\n2. JR Balmaceda | 9AM | Main Hall | Wed",
            'gathering_type' => 'pm',
        ]));
        $this->assertSame(200, $import->getStatusCode(), $import->getContent());
        $draft = AttendanceDraft::firstOrFail();
        $records = collect($draft->parsed_data)->map(fn ($row) => collect($row)->only([
            'student_id', 'gathering_type', 'day', 'status', 'batch', 'venue',
        ])->all())->all();
        $submitted = $controller->store($requestFor(['draft_id' => $draft->id, 'records' => $records]));
        $this->assertSame(201, $submitted->getStatusCode(), $submitted->getContent());
        $batchId = AttendanceRecord::firstOrFail()->batch_id;
        $this->assertNull(AttendanceRecord::firstOrFail()->unsubmitted_at);
        $this->assertNull(AttendanceRecord::firstOrFail()->resubmitted_at);
        $this->assertSame(['2026-10-28'], AttendanceRecord::pluck('reporting_day')->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->values()->all());

        $head = new User(['role' => 'program_head', 'program' => 'BSIS', 'year_level' => 1]);
        $head->id = 2;
        foreach (['program_head', 'cm_coordinator', 'chancellor'] as $role) {
            $head->role = $role;
            $request = $requestFor([], 'GET');
            $request->setUserResolver(fn () => $head);
            $review = $controller->reviewSubmission($request, $batchId)->getData(true)['data'];
            $dates = collect($review['students'])->pluck('day', 'student_name')->all();
            $this->assertSame('2026-10-26', $dates['Ariate Angelene']);
            $this->assertSame('2026-10-28', $dates['JR Balmaceda']);
            $this->assertSame('2026-10-28', $review['week_range']['start']);
        }

        $session = $controller->showGatheringRecords($requestFor(['batch_id' => $batchId], 'GET'))->getData(true)['data'];
        $this->assertSame('2026-10-28', $session['week_range']['start']);
        $this->assertSame('2026-10-28', collect($session['students'])->firstWhere('student_name', 'JR Balmaceda')['day']);
        AttendanceRecord::query()->update(['validation_status' => 'validated']);
        $head->role = 'program_head';
        $exportRows = app(\App\Services\ProgramHead\ProgramHeadRecordsExportService::class)->query($head, ['date' => '2026-10-28'])->get();
        $this->assertCount(2, $exportRows);
        $this->assertSame(['2026-10-26', '2026-10-28'], $exportRows->map(fn ($row) => $row->day->toDateString())->sort()->values()->all());
        $exportService = app(\App\Services\ProgramHead\ProgramHeadRecordsExportService::class);
        $jr = Student::where('name', 'JR Balmaceda')->firstOrFail();
        $jr->update(['year_level' => 2, 'is_active' => false]);
        AttendanceRecord::query()->update(['reporting_day' => null]);
        $batchRows = $exportService->query($head, [
            'batch_id' => $batchId, 'date' => '1900-01-01', 'year_level' => 1, 'search' => 'not a student',
        ])->get();
        $this->assertCount(2, $batchRows);
        $this->assertSame($exportRows->pluck('id')->sort()->values()->all(), $batchRows->pluck('id')->sort()->values()->all());
        $this->assertCount(2, $exportService->query($head, ['batch_key' => $batchId, 'date' => '1900-01-01', 'year_level' => 1])->get());
        $this->assertCount(0, $exportService->query($head, ['batch_id' => 'different-batch', 'year_level' => 1])->get());
        $jr->update(['year_level' => 1, 'is_active' => true]);
        AttendanceRecord::query()->update(['reporting_day' => '2026-10-28']);
        AttendanceRecord::query()->update(['validation_status' => 'unvalidated']);
        $monitor = app(\App\Services\Dashboard\CmCoordinatorMonitoringService::class);
        $monitoring = $monitor->getCourseDetails('BSIS', 1, ['month' => 10, 'year' => 2026, 'week' => 4]);
        $pm = collect($monitoring['week_details'])->firstWhere('gathering_type', 'pm');
        $this->assertSame(2, $pm['responded_students']);
        $this->assertSame(['2026-10-26', '2026-10-28'], $pm['dates']);

        $returned = $controller->unsubmitSubmission($requestFor([]), $batchId);
        $this->assertSame(200, $returned->getStatusCode(), $returned->getContent());
        $draft = AttendanceDraft::firstOrFail();
        $this->assertSame('2026-10-28', $draft->day->toDateString());
        $this->assertSame('2026-10-28', collect($draft->parsed_data)->firstWhere('student_name', 'JR Balmaceda')['day']);
        $monitoring = $monitor->getCourseDetails('BSIS', 1, ['month' => 10, 'year' => 2026, 'week' => 4]);
        $pm = collect($monitoring['week_details'])->firstWhere('gathering_type', 'pm');
        $this->assertSame(2, $pm['responded_students']);
        $this->assertSame(['2026-10-26', '2026-10-28'], $pm['dates']);

        // Editing next week must still validate against the original submission period.
        Carbon::setTestNow('2026-11-06 12:00:00');
        $draftController = app(AttendanceDraftController::class);
        $shown = $draftController->show($requestFor([], 'GET'), $draft->id, app(AttendanceParsingService::class))->getData(true)['data'];
        $this->assertSame('2026-10-28', $shown['week_range']['start']);
        $edited = $draftController->update($requestFor(['records' => collect($draft->parsed_data)->map(fn ($row) => [
            'student_id' => $row['student_id'], 'day' => $row['day'], 'day_provided' => true,
            'batch' => '10AM', 'venue' => $row['venue'],
        ])->all()], 'PATCH'), $draft->id, app(AttendanceParsingService::class), app(AuditLogService::class));
        $this->assertSame(200, $edited->getStatusCode(), $edited->getContent());
        $this->assertSame('2026-10-28', collect($draft->fresh()->parsed_data)->firstWhere('student_name', 'JR Balmaceda')['day']);
        $unsubmittedAt = $draft->fresh()->unsubmitted_at->toIso8601String();
        $resubmitted = $controller->store($requestFor([
            'draft_id' => $draft->id,
            'records' => collect($draft->fresh()->parsed_data)->map(fn ($row) => collect($row)->only([
                'student_id', 'gathering_type', 'day', 'status', 'batch', 'venue',
            ])->all())->all(),
        ]));
        $this->assertSame(201, $resubmitted->getStatusCode(), $resubmitted->getContent());
        $newRecord = AttendanceRecord::firstOrFail();
        $this->assertSame($unsubmittedAt, $newRecord->unsubmitted_at->toIso8601String());
        $this->assertTrue($newRecord->resubmitted_at->equalTo(Carbon::now()));
        $this->assertSame('unvalidated', $newRecord->validation_status);
        $this->assertNull($newRecord->approved_at);
        $this->assertNull($newRecord->validated_by);
        $reviewRequest = $requestFor([], 'GET');
        $head->role = 'program_head';
        $reviewRequest->setUserResolver(fn () => $head);
        $review = $controller->reviewSubmission($reviewRequest, $newRecord->batch_id)->getData(true)['data'];
        $this->assertSame($unsubmittedAt, $review['unsubmitted_at']);
        $this->assertSame($newRecord->resubmitted_at->toIso8601String(), $review['resubmitted_at']);
    }

    public function test_legacy_mixed_submission_uses_regular_attendance_for_its_period(): void
    {
        $special = new AttendanceRecord(['gathering_type' => 'pm', 'day' => '2026-10-26', 'venue' => 'Special']);
        $regular = new AttendanceRecord(['gathering_type' => 'pm', 'day' => '2026-10-28', 'venue' => 'Main Hall']);
        $this->assertSame('2026-10-28', \App\Support\AttendanceReportingPeriod::day(collect([$special, $regular]))->toDateString());
        $special->reporting_day = '2026-10-28';
        $this->assertSame('2026-10-28', \App\Support\AttendanceReportingPeriod::day(collect([$special]))->toDateString());
    }

    public function test_reporting_date_migration_preserves_individual_attendance_dates(): void
    {
        foreach ([['Special', '2026-10-26'], ['Main Hall', '2026-10-28']] as [$venue, $day]) {
            AttendanceRecord::create([
                'student_id' => 1, 'gathering_type' => 'pm', 'batch_id' => 'legacy-mixed',
                'day' => $day, 'venue' => $venue, 'validation_status' => 'unvalidated',
            ]);
        }
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropColumn('reporting_day'));
        $migration = require database_path('migrations/2026_10_30_000000_add_reporting_day_to_attendance_records.php');
        $migration->up();
        $this->assertSame(['2026-10-26', '2026-10-28'], AttendanceRecord::orderBy('id')->get()->map(fn ($record) => $record->day->toDateString())->all());
        $this->assertSame(['2026-10-28', '2026-10-28'], AttendanceRecord::orderBy('id')->get()->map(fn ($record) => $record->reporting_day->toDateString())->all());
    }

    public function test_previously_unsubmitted_mixed_draft_recovers_its_regular_pm_period(): void
    {
        $rows = [
            ['student_id' => 1, 'venue' => 'Special', 'day' => '2026-10-26', 'status' => 'present'],
            ['student_id' => 2, 'venue' => 'Main Hall', 'day' => '2026-10-28', 'status' => 'present'],
        ];
        $draft = AttendanceDraft::create([
            'user_id' => 1, 'gathering_type' => 'pm', 'day' => '2026-10-21',
            'status' => 'draft', 'parsed_data' => $rows,
        ]);
        $migration = require database_path('migrations/2026_10_30_000001_repair_mixed_special_draft_periods.php');
        $migration->up();
        $this->assertSame('2026-10-28', $draft->fresh()->day->toDateString());
        $this->assertSame($rows, $draft->fresh()->parsed_data);
    }

    public function test_approved_unsubmit_is_limited_to_current_and_previous_reporting_weeks_but_pending_is_not(): void
    {
        foreach ([
            ['validated', '2026-10-28', 200],
            ['validated', '2026-10-21', 200],
            ['validated', '2026-10-14', 409],
            ['validated', '2026-11-04', 409],
            ['unvalidated', '2026-09-02', 200],
        ] as $index => [$status, $period, $expected]) {
            $record = AttendanceRecord::create([
                'student_id' => Student::firstOrFail()->id, 'gathering_type' => 'pm',
                'day' => '2026-10-01', 'reporting_day' => $period, 'venue' => 'Special',
                'batch_id' => 'unsubmit-test-'.$index, 'submitted_by' => 1,
                'validation_status' => $status, 'validated_by' => $status === 'validated' ? 2 : null,
                'approved_at' => $status === 'validated' ? now() : null,
            ]);
            $request = Request::create('/attendance/unsubmit', 'POST');
            $request->setUserResolver(fn () => $this->secretary());
            $response = app(AttendanceController::class)->unsubmitSubmission($request, $record->batch_id);
            $this->assertSame($expected, $response->getStatusCode(), $response->getContent());
            if ($expected === 200) {
                $this->assertNull($record->fresh());
                $draft = AttendanceDraft::whereDate('day', $period)->firstOrFail();
                $this->assertNotNull($draft->unsubmitted_at);
                $this->assertSame('draft', $draft->status);
                $this->assertArrayNotHasKey('validated_by', $draft->parsed_data[0]);
                $this->assertSame('2026-10-01', $draft->parsed_data[0]['day']);
            } else {
                $this->assertNotNull($record->fresh());
                $this->assertSame($status, $record->fresh()->validation_status);
            }
        }
    }

    public function test_unsubmit_cannot_remove_another_secretarys_approved_submission(): void
    {
        $record = AttendanceRecord::create([
            'student_id' => Student::firstOrFail()->id, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'reporting_day' => '2026-10-28',
            'batch_id' => 'another-secretary', 'submitted_by' => 99, 'validation_status' => 'validated',
        ]);
        $request = Request::create('/attendance/unsubmit', 'POST');
        $request->setUserResolver(fn () => $this->secretary());
        $response = app(AttendanceController::class)->unsubmitSubmission($request, $record->batch_id);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($record->fresh());
    }

    public function test_existing_draft_retains_an_inactivated_student_but_new_submission_rejects_them(): void
    {
        $student = Student::firstOrFail();
        $entry = [
            'student_id' => $student->id, 'student_name' => $student->name,
            'program' => 'BSIS', 'year_level' => 1, 'gathering_type' => 'pm',
            'day' => '2026-10-28', 'day_provided' => true,
            'batch' => '08:00:00', 'venue' => 'Chapel', 'status' => 'present',
        ];
        $draft = AttendanceDraft::create([
            'user_id' => 1, 'gathering_type' => 'pm', 'status' => 'draft',
            'day' => '2026-10-28', 'parsed_data' => [$entry],
        ]);
        $student->update(['is_active' => false]);
        $edit = Request::create('/attendance/drafts/'.$draft->id, 'PATCH', ['records' => [[
            'student_id' => $student->id, 'day' => '2026-10-28', 'day_provided' => true,
            'batch' => '8AM', 'venue' => 'Chapel',
        ]]]);
        $edit->setUserResolver(fn () => $this->secretary());
        $response = app(AttendanceDraftController::class)->update($edit, $draft->id, app(AttendanceParsingService::class), app(AuditLogService::class));
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertCount(1, $draft->fresh()->parsed_data);

        $submit = Request::create('/attendance', 'POST', ['records' => [$entry]]);
        $submit->setUserResolver(fn () => $this->secretary());
        $this->assertSame(403, app(AttendanceController::class)->store($submit)->getStatusCode());
        $submit->merge(['draft_id' => $draft->id]);
        $submit->merge(['records' => [[...$entry, 'gathering_type' => 'ws']]]);
        $this->assertSame(422, app(AttendanceController::class)->store($submit)->getStatusCode());
        $submit->merge(['records' => [$entry]]);
        $response = app(AttendanceController::class)->store($submit);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id]);
    }

    private function secretary(): User
    {
        $user = new User(['program' => 'BSIS', 'year_level' => 1, 'role' => 'secretary']);
        $user->id = 1;
        return $user;
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
