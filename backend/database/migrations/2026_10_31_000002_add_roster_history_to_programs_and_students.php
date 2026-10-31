<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['programs', 'students'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('roster_history')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['programs', 'students'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('roster_history'));
        }
    }
};
