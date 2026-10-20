<?php

namespace App\Support;

use App\Models\SystemSetting;
use Carbon\Carbon;

class SystemTimezone
{
    public static function timezone(): string
    {
        try {
            $timezone = SystemSetting::query()->whereKey(1)->value('timezone');
        } catch (\Throwable) {
            // Keep Artisan commands such as the initial migrations usable
            // before the system_settings table exists.
            $timezone = null;
        }

        return $timezone ?: config('app.timezone', 'Asia/Manila');
    }

    public static function today(): Carbon
    {
        return Carbon::today(self::timezone());
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }
}
