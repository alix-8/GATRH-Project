<?php

namespace App\Services;

use App\Models\GatheringSchedule;
use App\Models\SystemSetting;
use Carbon\Carbon;

class GatheringService
{
    private const DEFAULT_SCHEDULES = [
        'pm' => ['wednesday'],
        'ws' => ['saturday', 'sunday'],
        'tg' => ['saturday', 'sunday'],
    ];

    private array $scheduleCache = [];

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

    public function scheduledDayNames(string $type): string
    {
        return collect($this->getScheduledDays($type))
            ->map(fn (string $day) => ucfirst($day))
            ->implode(' or ');
    }

    private function getScheduledDays(string $type): array
    {
        if (! array_key_exists($type, $this->scheduleCache)) {
            $this->scheduleCache[$type] = GatheringSchedule::query()
                ->where('gathering_type', $type)
                ->first()?->days ?? self::DEFAULT_SCHEDULES[$type] ?? [];
        }

        return $this->scheduleCache[$type];
    }

    private function today(): Carbon
    {
        $timezone = SystemSetting::query()->whereKey(1)->value('timezone')
            ?? config('app.timezone');

        return Carbon::today($timezone);
    }
}
