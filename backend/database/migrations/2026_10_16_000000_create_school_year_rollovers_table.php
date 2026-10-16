<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_year_rollovers', function (Blueprint $table) {
            $table->id();
            $table->string('school_year', 9)->unique();
            $table->unsignedInteger('promoted_students_count')->default(0);
            $table->unsignedInteger('graduated_students_count')->default(0);
            $table->unsignedInteger('promoted_secretaries_count')->default(0);
            $table->unsignedInteger('graduated_secretaries_count')->default(0);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_year_rollovers');
    }
};
