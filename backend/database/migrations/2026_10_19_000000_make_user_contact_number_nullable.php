<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('contact_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNull('contact_number')->update(['contact_number' => '']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('contact_number')->nullable(false)->change();
        });
    }
};

