<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->index(['program', 'year_level'], 'students_program_year_level_index');
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->index(['submitted_by', 'gathering_type', 'day'], 'attendance_submitter_gathering_day_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->dropIndex('attendance_submitter_gathering_day_index');
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->dropIndex('students_program_year_level_index');
        });
    }
};
