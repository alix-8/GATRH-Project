<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_year_rollovers')) {
            return;
        }

        Schema::table('school_year_rollovers', function (Blueprint $table) {
            if (! Schema::hasColumn('school_year_rollovers', 'promoted_students_count')) {
                $table->unsignedInteger('promoted_students_count')->default(0);
            }
            if (! Schema::hasColumn('school_year_rollovers', 'graduated_students_count')) {
                $table->unsignedInteger('graduated_students_count')->default(0);
            }
            if (! Schema::hasColumn('school_year_rollovers', 'promoted_secretaries_count')) {
                $table->unsignedInteger('promoted_secretaries_count')->default(0);
            }
            if (! Schema::hasColumn('school_year_rollovers', 'graduated_secretaries_count')) {
                $table->unsignedInteger('graduated_secretaries_count')->default(0);
            }
        });

        if (Schema::hasColumn('school_year_rollovers', 'promoted_count')) {
            DB::table('school_year_rollovers')->update([
                'promoted_students_count' => DB::raw('promoted_count'),
            ]);
        }

        if (Schema::hasColumn('school_year_rollovers', 'graduated_count')) {
            DB::table('school_year_rollovers')->update([
                'graduated_students_count' => DB::raw('graduated_count'),
            ]);
        }

        Schema::table('school_year_rollovers', function (Blueprint $table) {
            $legacyColumns = [];
            if (Schema::hasColumn('school_year_rollovers', 'promoted_count')) {
                $legacyColumns[] = 'promoted_count';
            }
            if (Schema::hasColumn('school_year_rollovers', 'graduated_count')) {
                $legacyColumns[] = 'graduated_count';
            }

            if ($legacyColumns !== []) {
                $table->dropColumn($legacyColumns);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_year_rollovers')) {
            return;
        }

        Schema::table('school_year_rollovers', function (Blueprint $table) {
            if (! Schema::hasColumn('school_year_rollovers', 'promoted_count')) {
                $table->unsignedInteger('promoted_count')->default(0);
            }
            if (! Schema::hasColumn('school_year_rollovers', 'graduated_count')) {
                $table->unsignedInteger('graduated_count')->default(0);
            }
        });

        DB::table('school_year_rollovers')->update([
            'promoted_count' => DB::raw('promoted_students_count'),
            'graduated_count' => DB::raw('graduated_students_count'),
        ]);

        Schema::table('school_year_rollovers', function (Blueprint $table) {
            $newColumns = [];
            foreach ([
                'promoted_students_count',
                'graduated_students_count',
                'promoted_secretaries_count',
                'graduated_secretaries_count',
            ] as $column) {
                if (Schema::hasColumn('school_year_rollovers', $column)) {
                    $newColumns[] = $column;
                }
            }

            if ($newColumns !== []) {
                $table->dropColumn($newColumns);
            }
        });
    }
};
