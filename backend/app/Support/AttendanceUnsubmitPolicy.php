<?php

namespace App\Support;

use Carbon\Carbon;

class AttendanceUnsubmitPolicy
{
    public static function approvedWeekAllowed($reportingDay): bool
    {
        $current = GatheringCycle::weekStartForDate(SystemTimezone::today());
        $week = GatheringCycle::weekStartForDate(Carbon::parse($reportingDay));
        return $week->equalTo($current) || $week->equalTo($current->copy()->subWeek());
    }
}
