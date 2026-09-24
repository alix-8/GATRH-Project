<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->string('reminder_type')->default('custom')->change();
        });

        // Reminders with no gathering data (and no countdown parent) are custom reminders
        // that were saved before reminder_type was set explicitly — they fell back to the
        // old 'gathering' column default.
        DB::table('reminders')
            ->whereNull('gathering_type')
            ->whereNull('program')
            ->whereNull('year_level')
            ->whereNull('gathering_date')
            ->whereNull('parent_reminder_id')
            ->where('reminder_type', 'gathering')
            ->update(['reminder_type' => 'custom']);
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->string('reminder_type')->default('gathering')->change();
        });
    }
};
