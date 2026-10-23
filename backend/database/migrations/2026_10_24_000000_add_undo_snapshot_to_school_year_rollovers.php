<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_year_rollovers', function (Blueprint $table) {
            $table->dropUnique('school_year_rollovers_school_year_unique');
            $table->index('school_year', 'school_year_rollovers_school_year_index');
            $table->json('snapshot')->nullable();
            $table->timestamp('undo_expires_at')->nullable();
            $table->timestamp('undone_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('school_year_rollovers', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'undo_expires_at', 'undone_at']);
            $table->dropIndex('school_year_rollovers_school_year_index');
            $table->unique('school_year', 'school_year_rollovers_school_year_unique');
        });
    }
};
