<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_drafts', function (Blueprint $table): void {
            $table->boolean('override_confirmed')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_drafts', function (Blueprint $table): void {
            $table->dropColumn('override_confirmed');
        });
    }
};
