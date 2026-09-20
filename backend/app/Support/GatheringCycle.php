<?php

namespace App\Support;

use Carbon\Carbon;

class GatheringCycle
{
    private const WINDOW_DAYS = [
        'pm' => 3, // Wed, Thu, Fri
        'ws' => 2, // Sat, Sun
        'tg' => 1, // Sat only
    ];

    private static function anchorDay(string $gatheringType): int
    {
        return match ($gatheringType) {
            'ws', 'tg' => Carbon::SATURDAY,
            default    => Carbon::WEDNESDAY,
        };
    }

    public static function currentCycleStart(string $gatheringType): Carbon
    {
        $anchorDay = self::anchorDay($gatheringType);
        $today = Carbon::today();
        $diff = ($today->dayOfWeek - $anchorDay + 7) % 7;
        return $today->copy()->subDays($diff)->startOfDay();
    }

    public static function currentWindowEnd(string $gatheringType): Carbon
    {
        $days = self::WINDOW_DAYS[$gatheringType] ?? 1;
        return self::currentCycleStart($gatheringType)->copy()->addDays($days - 1);
    }

    /**
     * Human-readable window label, e.g. "July 8-9" or "July 30-August 1"
     * when the window crosses a month boundary.
     */
    public static function windowLabel(string $gatheringType): string
    {
        $start = self::currentCycleStart($gatheringType);
        $end = self::currentWindowEnd($gatheringType);

        if ($start->isSameDay($end)) {
            return $start->format('F j');
        }

        if ($start->month === $end->month) {
            return $start->format('F j') . '-' . $end->format('j');
        }

        return $start->format('F j') . '-' . $end->format('F j');
    }
}