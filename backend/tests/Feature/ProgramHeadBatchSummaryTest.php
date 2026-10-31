<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProgramHeadBatchSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('program');
            $table->string('year_level');
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('batch_id')->nullable();
            $table->string('gathering_type');
            $table->date('day');
            $table->date('reporting_day')->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->string('validation_status')->nullable();
        });
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('escalation_level');
            $table->integer('no_response_count');
        });
        DB::table('students')->insert([
            ['id' => 1, 'program' => 'BSIS', 'year_level' => '4'],
            ['id' => 2, 'program' => 'BSIS', 'year_level' => '4'],
            ['id' => 3, 'program' => 'ACT', 'year_level' => '2'],
        ]);
    }

    private function summary(): array
    {
        $head = new class extends User {
            public function assignedProgramCodes(): array { return ['BSIS']; }
        };
        $head->role = 'program_head';
        return app(DashboardService::class)->getProgramHeadSummary($head, ['start' => '2026-10-01', 'end' => '2026-10-31']);
    }

    private function record(?string $batch, ?string $status, array $overrides = []): void
    {
        DB::table('attendance_records')->insert(array_merge([
            'student_id' => 1, 'batch_id' => $batch, 'gathering_type' => 'pm',
            'day' => '2026-10-21', 'reporting_day' => '2026-10-21',
            'submitted_by' => 10, 'validation_status' => $status,
        ], $overrides));
    }

    public function test_counts_whole_batches_and_requires_every_record_to_be_approved(): void
    {
        for ($i = 0; $i < 22; $i++) $this->record('complete', 'validated');
        $this->record('partial', 'validated');
        $this->record('partial', 'unvalidated', ['student_id' => 2]);
        $this->record('pending', null);
        $this->record('legacy-approved-status', 'approved');
        $this->record('outside-scope', 'validated', ['student_id' => 3]);
        $this->record('outside-period', 'validated', ['reporting_day' => '2026-09-23']);
        $this->assertSame(['pending_review' => 2, 'approved' => 2, 'escalation_count' => 0, 'total_submissions' => 4], $this->summary());
        DB::table('attendance_records')->where('batch_id', 'partial')->update(['validation_status' => 'validated']);
        $this->assertSame(3, $this->summary()['approved']);
        // Unsubmission removes the submitted rows; drafts do not enter this query.
        DB::table('attendance_records')->where('batch_id', 'partial')->delete();
        $this->assertSame(3, $this->summary()['total_submissions']);
        $this->assertSame(2, $this->summary()['approved']);
    }

    public function test_legacy_fallback_groups_by_reporting_day_gathering_and_submitter(): void
    {
        $this->record(null, 'validated');
        $this->record('', 'validated', ['student_id' => 2, 'day' => '2026-10-19']);
        $this->record(null, 'unvalidated', ['gathering_type' => 'ws']);
        $this->record(null, 'validated', ['submitted_by' => 11]);
        $this->record(null, 'validated', ['reporting_day' => null, 'day' => '2026-10-28']);
        $this->assertSame(4, $this->summary()['total_submissions']);
        $this->assertSame(3, $this->summary()['approved']);
        $this->assertSame(1, $this->summary()['pending_review']);
    }

    public function test_empty_scope_has_zero_submission_counts(): void
    {
        $this->assertSame(['pending_review' => 0, 'approved' => 0, 'escalation_count' => 0, 'total_submissions' => 0], $this->summary());
    }
}
