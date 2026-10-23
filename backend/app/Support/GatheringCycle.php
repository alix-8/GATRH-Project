<?php

namespace App\Support;

use Carbon\Carbon;

class GatheringCycle
{
    private const WINDOW_DAYS = [
        'pm' => 3, // Wed, Thu, Fri
        'ws' => 2, // Sat, Sun
        'tg' => 2, // Sat, Sun
    ];

    /**
     * Saved / configured cycle week ranges.
     * Can hold:
     * - ['start' => Carbon, 'end' => Carbon]
     * - or ['pm' => ['start' => Carbon, 'end' => Carbon], 'ws' => ..., 'tg' => ...]
     */
    private static ?array $savedWeekRanges = null;

    private static function anchorDay(string $gatheringType): int
    {
        return match ($gatheringType) {
            'ws', 'tg' => Carbon::SATURDAY,
            default    => Carbon::WEDNESDAY,
        };
    }

    /**
     * Parses a string representation of a week range.
     */
    public static function parseRangeString(string $str): ?array
    {
        $clean = trim($str);

        // 1. ISO format: 2026-10-05 to 2026-10-11 or 2026-10-05 - 2026-10-11
        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*(?:through|to|-)\s*(\d{4}-\d{2}-\d{2})/i', $clean, $m)) {
            return [
                'start' => Carbon::parse($m[1])->startOfDay(),
                'end'   => Carbon::parse($m[2])->startOfDay(),
            ];
        }

        // 2. Full names or month day: "Monday, October 5 through Sunday, October 11"
        if (preg_match('/^(?:.*?,\s*)?([A-Za-z]+\s+\d{1,2}(?:,\s*\d{4})?)\s+(?:through|to|-)\s+(?:.*?,\s*)?([A-Za-z]+\s+\d{1,2}(?:,\s*\d{4})?)$/i', $clean, $m)) {
            try {
                $start = Carbon::parse($m[1])->startOfDay();
                $end = Carbon::parse($m[2])->startOfDay();
                if ($end->lessThan($start)) {
                    $end->addYear();
                }
                return ['start' => $start, 'end' => $end];
            } catch (\Exception $e) {
            }
        }

        // 3. Same month compact: "October 5-11" or "October 5-11, 2026"
        if (preg_match('/([A-Za-z]+)\s+(\d{1,2})\s*-\s*(\d{1,2})(?:,\s*(\d{4}))?/i', $clean, $m)) {
            $month = $m[1];
            $startDay = $m[2];
            $endDay = $m[3];
            $year = $m[4] ?? Carbon::today()->year;
            try {
                $start = Carbon::parse("{$month} {$startDay}, {$year}")->startOfDay();
                $end = Carbon::parse("{$month} {$endDay}, {$year}")->startOfDay();
                return ['start' => $start, 'end' => $end];
            } catch (\Exception $e) {
            }
        }

        // 4. Cross month compact: "September 30-October 2"
        if (preg_match('/([A-Za-z]+)\s+(\d{1,2})\s*-\s*([A-Za-z]+)\s+(\d{1,2})(?:,\s*(\d{4}))?/i', $clean, $m)) {
            $year = $m[5] ?? Carbon::today()->year;
            try {
                $start = Carbon::parse("{$m[1]} {$m[2]}, {$year}")->startOfDay();
                $end = Carbon::parse("{$m[3]} {$m[4]}, {$year}")->startOfDay();
                if ($end->lessThan($start)) {
                    $end->addYear();
                }
                return ['start' => $start, 'end' => $end];
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * Normalizes inputs into ['start' => Carbon, 'end' => Carbon].
     */
    public static function normalizeRange($start, $end = null): ?array
    {
        if (is_string($start) && $end === null) {
            $parsed = self::parseRangeString($start);
            if ($parsed) return $parsed;
        }

        if (is_array($start)) {
            $s = $start['start'] ?? $start['start_date'] ?? $start['from'] ?? null;
            $e = $start['end'] ?? $start['end_date'] ?? $start['to'] ?? null;
            if ($s && $e) {
                return [
                    'start' => ($s instanceof Carbon) ? $s->copy()->startOfDay() : Carbon::parse($s)->startOfDay(),
                    'end'   => ($e instanceof Carbon) ? $e->copy()->startOfDay() : Carbon::parse($e)->startOfDay(),
                ];
            }
        }

        if ($start !== null && $end !== null) {
            return [
                'start' => ($start instanceof Carbon) ? $start->copy()->startOfDay() : Carbon::parse($start)->startOfDay(),
                'end'   => ($end instanceof Carbon) ? $end->copy()->startOfDay() : Carbon::parse($end)->startOfDay(),
            ];
        }

        return null;
    }

    /**
     * Configure or save the active cycle week ranges.
     */
    public static function setConfiguredWeekRanges(array $ranges): void
    {
        $single = self::normalizeRange($ranges);
        if ($single) {
            self::$savedWeekRanges = $single;
            return;
        }

        self::$savedWeekRanges = [];
        foreach ($ranges as $type => $range) {
            $norm = self::normalizeRange($range);
            if ($norm) {
                self::$savedWeekRanges[strtolower($type)] = $norm;
            }
        }
    }

    public static function setConfiguredWeekRange($start, $end = null, ?string $gatheringType = null): void
    {
        $norm = self::normalizeRange($start, $end);
        if (!$norm) return;

        if ($gatheringType) {
            if (!is_array(self::$savedWeekRanges)) {
                self::$savedWeekRanges = [];
            }
            self::$savedWeekRanges[strtolower($gatheringType)] = $norm;
        } else {
            self::$savedWeekRanges = $norm;
        }
    }

    public static function setWeekRanges(array $ranges): void
    {
        self::setConfiguredWeekRanges($ranges);
    }

    public static function setWeekRange($start, $end = null, ?string $gatheringType = null): void
    {
        self::setConfiguredWeekRange($start, $end, $gatheringType);
    }

    public static function clearConfiguredWeekRanges(): void
    {
        self::$savedWeekRanges = null;
    }

    public static function getSavedWeekRanges(): ?array
    {
        return self::$savedWeekRanges;
    }

    public static function hasConfiguredWeekRanges(?string $gatheringType = null): bool
    {
        if (self::$savedWeekRanges === null) return false;
        if ($gatheringType === null) return !empty(self::$savedWeekRanges);

        $type = strtolower($gatheringType);
        return isset(self::$savedWeekRanges[$type]) || (isset(self::$savedWeekRanges['start']) && isset(self::$savedWeekRanges['end']));
    }

    public static function getWeekRange(string $gatheringType): array
    {
        $type = strtolower($gatheringType);

        if (isset(self::$savedWeekRanges[$type]['start']) && isset(self::$savedWeekRanges[$type]['end'])) {
            return [
                'start' => self::$savedWeekRanges[$type]['start']->copy(),
                'end'   => self::$savedWeekRanges[$type]['end']->copy(),
            ];
        }

        if (isset(self::$savedWeekRanges['start']) && isset(self::$savedWeekRanges['end'])) {
            return [
                'start' => self::$savedWeekRanges['start']->copy(),
                'end'   => self::$savedWeekRanges['end']->copy(),
            ];
        }

        return [
            'start' => self::currentCycleStart($gatheringType),
            'end'   => self::currentWindowEnd($gatheringType),
        ];
    }

    /** Returns each gathering's reporting window for the selected import period. */
    public static function getImportPeriodRanges(string $period): array
    {
        $offset = $period === 'last' ? -7 : 0;
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->addDays($offset);
        return self::getRangesForWeekStart($weekStart);
    }

    /** Returns the configured gathering windows for a Monday-to-Sunday reporting week. */
    public static function getRangesForWeekStart(Carbon $weekStart): array
    {
        $weekStart = $weekStart->copy()->startOfWeek(Carbon::MONDAY);
        $ranges = [];
        foreach (['pm', 'ws', 'tg'] as $type) {
            $start = $weekStart->copy()->addDays(match ($type) { 'pm' => 2, default => 5 });
            $days = self::WINDOW_DAYS[$type] ?? 1;
            $end = $start->copy()->addDays($days - 1);
            $ranges[$type] = ['start' => $start, 'end' => $end];
        }
        return $ranges;
    }

    public static function formatRangeLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end)) return $start->format('F j') . '-' . $end->format('j, Y');
        return $start->format('F j') . '-' . $end->format('F j, Y');
    }

    public static function currentCycleStart(string $gatheringType): Carbon
    {
        $type = strtolower($gatheringType);
        if (isset(self::$savedWeekRanges[$type]['start'])) {
            return self::$savedWeekRanges[$type]['start']->copy();
        }
        if (isset(self::$savedWeekRanges['start'])) {
            return self::$savedWeekRanges['start']->copy();
        }

        $anchorDay = self::anchorDay($gatheringType);
        $today = Carbon::today();
        $diff = ($today->dayOfWeek - $anchorDay + 7) % 7;
        return $today->copy()->subDays($diff)->startOfDay();
    }

    public static function currentWindowEnd(string $gatheringType): Carbon
    {
        $type = strtolower($gatheringType);
        if (isset(self::$savedWeekRanges[$type]['end'])) {
            return self::$savedWeekRanges[$type]['end']->copy();
        }
        if (isset(self::$savedWeekRanges['end'])) {
            return self::$savedWeekRanges['end']->copy();
        }

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

    /** Returns the gathering's scheduled window in the current calendar week. */
    public static function reportWeekWindowLabel(string $gatheringType): string
    {
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $offset = match (strtolower($gatheringType)) {
            'pm' => 2,
            'ws', 'tg' => 5,
            default => 0,
        };
        $windowDays = self::WINDOW_DAYS[strtolower($gatheringType)] ?? 1;
        $start = $weekStart->copy()->addDays($offset);
        $end = $start->copy()->addDays($windowDays - 1);

        if ($start->isSameDay($end)) {
            return $start->format('F j');
        }

        if ($start->month === $end->month) {
            return $start->format('F j') . '-' . $end->format('j');
        }

        return $start->format('F j') . '-' . $end->format('F j');
    }
}
