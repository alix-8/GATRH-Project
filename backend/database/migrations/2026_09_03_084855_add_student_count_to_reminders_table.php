<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->string('reminder_type')->default('gathering')->after('user_id');

            $table->foreignId('parent_reminder_id')->nullable()->after('reminder_type')
                ->constrained('reminders')->cascadeOnDelete();

            $table->enum('gathering_type', ['pm', 'ws', 'tg', 'special'])->nullable()->after('parent_reminder_id');
            $table->string('program')->nullable()->after('gathering_type');
            $table->integer('year_level')->nullable()->after('program');
            $table->date('gathering_date')->nullable()->after('year_level');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropForeign(['parent_reminder_id']);
            $table->dropColumn([
                'reminder_type',
                'parent_reminder_id',
                'gathering_type',
                'program',
                'year_level',
                'gathering_date',
            ]);
        });
    }
};