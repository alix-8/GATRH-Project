<?php

namespace App\Services;

use Carbon\Carbon;

class GatheringService
{
    public function label(string $type): string
    {
        return match ($type) {
            'pm'      => 'Prayer meeting',
            'ws'      => 'Worship service',
            'tg'      => 'Thanksgiving',
            'special' => 'Special gathering',
            default   => $type,
        };
    }

    public function nextDate(string $type): string
    {
        $weekday = $type === 'pm' ? Carbon::WEDNESDAY : Carbon::SATURDAY;

        return Carbon::today()->next($weekday)->toDateString();
    }

    public function isScheduledDay(string $type, Carbon $date): bool
    {
        return match ($type) {
            'pm' => $date->isWednesday(),
            'ws', 'tg' => $date->isSaturday() || $date->isSunday(),
            default => false,
        };
    }
}
