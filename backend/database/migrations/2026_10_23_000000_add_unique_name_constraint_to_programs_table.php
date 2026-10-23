<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateNames = DB::table('programs')
            ->select('name')
            ->whereNotNull('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        if ($duplicateNames->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add the unique program name constraint until duplicate program names are resolved: '
                . $duplicateNames->implode(', ')
            );
        }

        Schema::table('programs', function (Blueprint $table): void {
            $table->unique('name', 'programs_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropUnique('programs_name_unique');
        });
    }
};
