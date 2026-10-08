<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('institution_name')->default('La Verdad Christian College');
            $table->string('academic_year', 9)->default('2026-2027');
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('date_format', 20)->default('MM/DD/YYYY');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('system_settings')->insert([
            'id' => 1,
            'institution_name' => 'La Verdad Christian College',
            'academic_year' => '2026-2027',
            'timezone' => 'Asia/Manila',
            'date_format' => 'MM/DD/YYYY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
