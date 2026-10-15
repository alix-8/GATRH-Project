<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve legacy rejected records as pending so they stay in the review workflow.
        DB::table('attendance_records')
            ->where('validation_status', 'rejected')
            ->update(['validation_status' => 'unvalidated']);

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->dropColumn('rejection_reason');
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->enum('validation_status', ['unvalidated', 'validated'])
                ->default('unvalidated')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->enum('validation_status', ['unvalidated', 'validated', 'rejected'])
                ->default('unvalidated')
                ->change();
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->text('rejection_reason')->nullable()->after('validation_status');
        });
    }
};
