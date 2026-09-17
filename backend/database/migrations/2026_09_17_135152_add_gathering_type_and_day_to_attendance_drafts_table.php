<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_drafts', function (Blueprint $table) {
            $table->enum('gathering_type', ['pm', 'ws', 'tg', 'special'])->nullable()->after('user_id');
            $table->date('day')->nullable()->after('gathering_type');
            $table->unique(['user_id', 'gathering_type', 'day']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_drafts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'gathering_type', 'day']);
            $table->dropColumn(['gathering_type', 'day']);
        });
    }
};