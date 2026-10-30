<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_export_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('program', 100);
            $table->unsignedTinyInteger('year_level');
            $table->date('month');
            $table->string('exported_hash', 64)->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->boolean('exported_approved')->default(false);
            $table->string('exported_approval_set', 64)->nullable();
            $table->string('download_token', 64)->nullable();
            $table->string('download_hash', 64)->nullable();
            $table->boolean('download_approved')->default(false);
            $table->string('download_approval_set', 64)->nullable();
            $table->timestamp('download_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'program', 'year_level', 'month'], 'monthly_export_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_export_tasks');
    }
};
