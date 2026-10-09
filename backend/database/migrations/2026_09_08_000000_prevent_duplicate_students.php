<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateStudents = DB::table('students as duplicate_students')
            ->select('duplicate_students.id as id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('students as existing_students')
                    ->whereColumn('duplicate_students.name', 'existing_students.name')
                    ->whereColumn('duplicate_students.program', 'existing_students.program')
                    ->whereColumn('duplicate_students.year_level', 'existing_students.year_level')
                    ->whereColumn('duplicate_students.id', '>', 'existing_students.id');
            });

        $duplicateStudents->chunkById(500, function ($students): void {
            DB::table('students')->whereIn('id', $students->pluck('id'))->delete();
        }, 'duplicate_students.id', 'id');

        Schema::table('students', function (Blueprint $table) {
            $table->unique(
                ['name', 'program', 'year_level'],
                'students_name_program_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_name_program_year_unique');
        });
    }
};
