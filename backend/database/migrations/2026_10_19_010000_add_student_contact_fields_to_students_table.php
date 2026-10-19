<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('student_number')->nullable()->unique()->after('id');
            $table->string('email')->nullable()->unique()->after('student_number');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropUnique(['student_number']);
            $table->dropColumn(['email', 'student_number']);
        });
    }
};
