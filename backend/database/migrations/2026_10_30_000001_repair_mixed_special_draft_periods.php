<?php

use App\Support\GatheringCycle;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Older unsubmit logic could anchor the draft to a special attendee's week.
        // Recover only when regular attendance identifies one unambiguous period.
        DB::table('attendance_drafts')->where('status', 'draft')->orderBy('id')->chunkById(200, function ($drafts): void {
            foreach ($drafts as $draft) {
                $entries = collect(json_decode($draft->parsed_data ?? '[]', true) ?: []);
                if (!$entries->contains(fn ($entry) => strcasecmp(trim((string) ($entry['venue'] ?? '')), 'Special') === 0)) {
                    continue;
                }
                $periods = $entries->filter(fn ($entry) =>
                    strcasecmp(trim((string) ($entry['venue'] ?? '')), 'Special') !== 0
                    && ($entry['status'] ?? null) === 'present'
                    && !empty($entry['day'])
                )->map(function ($entry) use ($draft) {
                    $date = Carbon::parse($entry['day']);
                    $range = GatheringCycle::getRangesForWeekStart($date)[$draft->gathering_type];
                    return $date->betweenIncluded($range['start'], $range['end'])
                        ? $range['start']->toDateString() : null;
                })->filter()->unique()->values();

                if ($periods->count() === 1 && $periods[0] !== $draft->day) {
                    DB::table('attendance_drafts')->where('id', $draft->id)->update(['day' => $periods[0]]);
                }
            }
        });
    }

    public function down(): void
    {
        // Do not restore incorrectly inferred periods or alter attendance dates.
    }
};
