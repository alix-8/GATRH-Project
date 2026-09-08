<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            $table->longText('raw_text');
            $table->json('parsed_data');   // Holds structured records array
            
            $table->integer('total_detected')->default(0);
            $table->integer('matched_count')->default(0);
            $table->string('status')->default('draft'); // e.g., draft, submitted, validated
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_drafts');
    }
};
