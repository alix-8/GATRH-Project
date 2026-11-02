<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('inactive_reason', 20)->nullable()->after('is_active')->index();
        });

        if (! Schema::hasTable('school_year_rollovers')) {
            return;
        }

        DB::table('school_year_rollovers')
            ->whereNull('undone_at')
            ->whereNotNull('snapshot')
            ->orderBy('id')
            ->each(function (object $rollover): void {
                $snapshot = json_decode($rollover->snapshot, true);

                foreach ($snapshot['students'] ?? [] as $student) {
                    $wasActive = (bool) ($student['before']['is_active'] ?? false);
                    $graduated = ! (bool) ($student['after']['is_active'] ?? true);

                    if (! $wasActive || ! $graduated || empty($student['id'])) {
                        continue;
                    }

                    // Only mark students who remain inactive. A later reactivation
                    // is a deliberate change and must not be overwritten here.
                    DB::table('students')
                        ->where('id', $student['id'])
                        ->where('is_active', false)
                        ->update(['inactive_reason' => 'graduated']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropIndex(['inactive_reason']);
            $table->dropColumn('inactive_reason');
        });
    }
};
