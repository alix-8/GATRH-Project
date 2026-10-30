<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('reminders', 'completed_at')) {
            Schema::table('reminders', fn (Blueprint $table) => $table->timestamp('completed_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('reminders', fn (Blueprint $table) => $table->dropColumn('completed_at'));
    }
};
