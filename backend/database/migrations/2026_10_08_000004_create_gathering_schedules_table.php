<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gathering_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('gathering_type', 10)->unique();
            $table->json('days');
            $table->timestamps();
        });

        $now = now();

        DB::table('gathering_schedules')->insert([
            ['gathering_type' => 'pm', 'days' => json_encode(['wednesday']), 'created_at' => $now, 'updated_at' => $now],
            ['gathering_type' => 'ws', 'days' => json_encode(['saturday', 'sunday']), 'created_at' => $now, 'updated_at' => $now],
            ['gathering_type' => 'tg', 'days' => json_encode(['saturday', 'sunday']), 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gathering_schedules');
    }
};
