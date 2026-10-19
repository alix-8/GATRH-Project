<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columnsToDrop = collect(['email', 'student_number'])
            ->filter(fn (string $column) => Schema::hasColumn('students', $column))
            ->values()
            ->all();

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('students', function (Blueprint $table) use ($columnsToDrop): void {
            foreach ($columnsToDrop as $column) {
                $table->dropUnique([$column]);
            }

            $table->dropColumn($columnsToDrop);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            if (! Schema::hasColumn('students', 'student_number')) {
                $table->string('student_number')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('students', 'email')) {
                $table->string('email')->nullable()->unique()->after('student_number');
            }
        });
    }
};
