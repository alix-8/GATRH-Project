<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE duplicate_students
            FROM students AS duplicate_students
            INNER JOIN students AS existing_students
                ON duplicate_students.name = existing_students.name
                AND duplicate_students.program = existing_students.program
                AND duplicate_students.year_level = existing_students.year_level
                AND duplicate_students.id > existing_students.id
        SQL);

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