<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_year_level')->default(4)->after('is_active');
        });

        DB::table('programs')->where('code', 'ACT')->update(['max_year_level' => 2]);
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropColumn('max_year_level');
        });
    }
};
