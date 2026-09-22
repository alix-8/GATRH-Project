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
        DB::statement("ALTER TABLE attendance_records MODIFY validation_status ENUM('unvalidated','validated','rejected') NOT NULL DEFAULT 'unvalidated'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE attendance_records MODIFY validation_status ENUM('unvalidated','validated') NOT NULL DEFAULT 'unvalidated'");
    }
};
