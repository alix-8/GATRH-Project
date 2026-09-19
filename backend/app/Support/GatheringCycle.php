<?php

namespace App\Support;

use Carbon\Carbon;

class GatheringCycle
{
    /**
     * The anchor weekday each gathering type resets on.
     * PM resets every Wednesday; WS and TG reset every Saturday.
     */
    private static function anchorDay(string $gatheringType): int
    {
        return match ($gatheringType) {
            'ws', 'tg' => Carbon::SATURDAY,
            default    => Carbon::WEDNESDAY, // 'pm'
        };
    }

    /**
     * The start date of the current cycle (the most recent anchor day,
     * including today if today IS the anchor day).
     */
    public static function currentCycleStart(string $gatheringType): Carbon
    {
        $anchorDay = self::anchorDay($gatheringType);
        $today = Carbon::today();

        $diff = ($today->dayOfWeek - $anchorDay + 7) % 7;

        return $today->copy()->subDays($diff)->startOfDay();
    }
}