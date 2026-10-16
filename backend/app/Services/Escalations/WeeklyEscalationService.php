<?php

namespace App\Services\Escalations;

use App\Models\AttendanceRecord;
use App\Models\Escalation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WeeklyEscalationService
{
    public function countsForStudents(iterable $studentIds): Collection
    {
        $ids = collect($studentIds)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $end = Carbon::now()->endOfWeek(Carbon::SUNDAY)->toDateString();

        return AttendanceRecord::query()
            ->whereIn('student_id', $ids)
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])
            ->whereBetween('day', [$start, $end])
            ->orderByDesc('day')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('student_id')
            ->map(fn (Collection $records) => $records
                ->groupBy('gathering_type')
                ->filter(fn (Collection $gatheringRecords) => $gatheringRecords->first()->status === 'no_response')
                ->count());
    }

    public function syncStudent(int $studentId): void
    {
        $count = min(3, $this->countsForStudents([$studentId])->get($studentId, 0));
        $level = match (true) {
            $count >= 3 => 'Level 3',
            $count === 2 => 'Level 2',
            $count === 1 => 'Level 1',
            default => 'None',
        };

        Escalation::updateOrCreate(
            ['student_id' => $studentId],
            ['no_response_count' => $count, 'escalation_level' => $level]
        );
    }

    public function syncAll(): int
    {
        $studentIds = Escalation::query()->pluck('student_id')
            ->merge(AttendanceRecord::query()->distinct()->pluck('student_id'))
            ->unique();

        foreach ($studentIds as $studentId) {
            $this->syncStudent((int) $studentId);
        }

        return $studentIds->count();
    }
}
