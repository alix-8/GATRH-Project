<?php

namespace App\Services\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\AttendanceReportingPeriod;
use Illuminate\Support\Collection;

class SecretaryRecentAttendanceQuery
{
    /** Pick complete submissions before fetching their student rows. */
    public function records(User $user, int $limit = 5): Collection
    {
        $base = AttendanceRecord::query()
            ->whereHas('student', fn ($students) => $students->accessibleTo($user));
        $hasBatch = "batch_id IS NOT NULL AND batch_id NOT IN ('', '0')";
        $batch = "CASE WHEN {$hasBatch} THEN batch_id ELSE NULL END";
        $type = "CASE WHEN {$hasBatch} THEN NULL ELSE gathering_type END";
        $day = "CASE WHEN {$hasBatch} THEN NULL ELSE DATE(day) END";
        $groups = (clone $base)
            ->selectRaw("{$batch} as group_batch, {$type} as group_type, {$day} as group_day")
            ->selectRaw('MIN(id) as first_id')
            ->selectRaw('MIN(CASE WHEN reporting_day IS NOT NULL THEN id END) as reporting_id')
            ->selectRaw("MIN(CASE WHEN LOWER(TRIM(COALESCE(venue, ''))) <> 'special' THEN id END) as regular_id")
            ->groupByRaw("{$batch}, {$type}, {$day}")
            ->orderBy('first_id')->get()->toBase();
        if ($groups->isEmpty()) return collect();

        // Legacy periods can depend on a regular attendee rather than the
        // earliest actual date. Read those representatives without full rows.
        $ids = $groups->flatMap(fn ($group) => [$group->first_id, $group->reporting_id, $group->regular_id])->filter()->unique();
        $sources = (clone $base)->whereKey($ids)
            ->get(['id', 'day', 'reporting_day', 'venue', 'gathering_type'])->keyBy('id');
        $selected = $groups->sortByDesc(function ($group) use ($sources) {
            $representatives = collect([$group->first_id, $group->reporting_id, $group->regular_id])
                ->filter()->unique()->sort()->map(fn ($id) => $sources->get($id))->filter()->values();
            return AttendanceReportingPeriod::day($representatives)->toDateString();
        })->take($limit);

        return (clone $base)->where(function ($query) use ($selected, $hasBatch) {
            foreach ($selected as $group) {
                $query->orWhere(function ($submission) use ($group, $hasBatch) {
                    if ($group->group_batch !== null) {
                        $submission->where('batch_id', $group->group_batch);
                    } else {
                        $submission->whereRaw("NOT ({$hasBatch})")
                            ->where('gathering_type', $group->group_type)->whereDate('day', $group->group_day);
                    }
                });
            }
        })->orderBy('id')->get();
    }
}
