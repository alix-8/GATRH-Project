<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceReportingPeriod
{
    /** Attendance dates belong to students; the reporting date belongs to the batch. */
    public static function day(Collection $records): Carbon
    {
        $saved = $records->first(fn ($record) => !empty($record->reporting_day));
        if ($saved) {
            return Carbon::parse($saved->reporting_day)->startOfDay();
        }

        // Compatibility for submissions created before reporting_day was stored.
        // A special attendee must never determine the period of regular attendees.
        $regular = $records->filter(fn ($record) => strcasecmp(trim((string) $record->venue), 'Special') !== 0);
        $source = ($regular->isNotEmpty() ? $regular : $records)->first();
        return GatheringCycle::getRangesForWeekStart(Carbon::parse($source->day))[$source->gathering_type]['start'];
    }
}
