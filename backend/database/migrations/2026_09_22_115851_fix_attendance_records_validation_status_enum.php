<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->enum('validation_status', ['unvalidated', 'validated', 'rejected'])
                ->default('unvalidated')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->enum('validation_status', ['unvalidated', 'validated'])
                ->default('unvalidated')
                ->change();
        });
    }
};
