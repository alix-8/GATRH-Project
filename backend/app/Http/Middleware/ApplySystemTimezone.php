<?php

namespace App\Http\Middleware;

use App\Support\SystemTimezone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySystemTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = SystemTimezone::timezone();

        if ($timezone) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        return $next($request);
    }
}
