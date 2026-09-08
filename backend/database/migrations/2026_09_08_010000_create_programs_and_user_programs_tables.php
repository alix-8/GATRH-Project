<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('program_user', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['program_id', 'user_id']);
        });

        DB::table('students')->select('program')->distinct()->pluck('program')->each(function (string $code): void {
            DB::table('programs')->insertOrIgnore([
                'code' => strtoupper(trim($code)),
                'name' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('users')
            ->whereNotNull('program')
            ->get(['id', 'program'])
            ->each(function (object $user): void {
                $programId = DB::table('programs')
                    ->where('code', strtoupper(trim($user->program)))
                    ->value('id');

                if ($programId) {
                    DB::table('program_user')->insertOrIgnore([
                        'program_id' => $programId,
                        'user_id' => $user->id,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_user');
        Schema::dropIfExists('programs');
    }
};