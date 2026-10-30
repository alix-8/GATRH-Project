<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->timestamp('unsubmitted_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropColumn(['unsubmitted_at', 'resubmitted_at']));
    }
};
