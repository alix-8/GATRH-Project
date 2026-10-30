<?php

use App\Support\AttendanceReportingPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->date('reporting_day')->nullable()->index();
        });

        DB::table('attendance_records')->select('batch_id')->whereNotNull('batch_id')->where('batch_id', '<>', '')
            ->distinct()->orderBy('batch_id')->chunk(200, function ($batches): void {
                foreach ($batches as $batch) {
                    $records = DB::table('attendance_records')->where('batch_id', $batch->batch_id)->get();
                    foreach ($records->groupBy('gathering_type') as $group) {
                        DB::table('attendance_records')->whereIn('id', $group->pluck('id'))
                            ->update(['reporting_day' => AttendanceReportingPeriod::day($group)->toDateString()]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropColumn('reporting_day'));
    }
};
