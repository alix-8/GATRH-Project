<?php

namespace App\Services\Reminders;

use App\Support\SystemTimezone;
use Carbon\Carbon;

class GatheringService
{
    private const DEFAULT_SCHEDULES = [
        'pm' => ['wednesday'],
        'ws' => ['saturday'],
        'tg' => ['saturday'],
    ];

    public function label(string $type): string
    {
        return match ($type) {
            'pm'      => 'Prayer meeting',
            'ws'      => 'Worship service',
            'tg'      => 'Thanksgiving',
            default   => $type,
        };
    }

    public function nextDate(string $type): string
    {
        $scheduledDays = $this->getScheduledDays($type);
        $today = $this->today();

        foreach (range(1, 7) as $daysFromToday) {
            $date = $today->copy()->addDays($daysFromToday);

            if (in_array(strtolower($date->format('l')), $scheduledDays, true)) {
                return $date->toDateString();
            }
        }

        return $today->addWeek()->toDateString();
    }

    public function isScheduledDay(string $type, Carbon $date): bool
    {
        return in_array(
            strtolower($date->format('l')),
            $this->getScheduledDays($type),
            true
        );
    }

    public function countScheduledOccurrences(string $type, Carbon $start, Carbon $end): int
    {
        $count = 0;
        $date = $start->copy()->startOfDay();
        $lastDate = $end->copy()->startOfDay();

        while ($date->lessThanOrEqualTo($lastDate)) {
            if ($this->isScheduledDay($type, $date)) {
                $count++;
            }

            $date->addDay();
        }

        return $count;
    }

    public function scheduledDayNames(string $type): string
    {
        return collect($this->getScheduledDays($type))
            ->map(fn (string $day) => ucfirst($day))
            ->implode(' or ');
    }

    private function getScheduledDays(string $type): array
    {
        // Reminder dates are the primary gathering days. Import attendance
        // windows are handled separately by GatheringCycle.
        return self::DEFAULT_SCHEDULES[$type] ?? [];
    }

    private function today(): Carbon
    {
        return SystemTimezone::today();
    }
}
