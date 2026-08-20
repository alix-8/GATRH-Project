<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->enum('gathering_type', ['pm', 'ws', 'tg', 'special']);
            $table->date('day');
            $table->string('batch')->nullable();
            $table->string('venue')->nullable();
            $table->enum('status', ['present', 'no_response'])->default('no_response');
            $table->string('picture_url')->nullable();
            $table->enum('validation_status', ['unvalidated', 'approved', 'rejected'])->default('unvalidated');
            $table->foreignId('submitted_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'gathering_type', 'day'], 'uq_student_gathering_day');
        });
    }

    public function down(): void {
        Schema::dropIfExists('attendance_records');
    }
};