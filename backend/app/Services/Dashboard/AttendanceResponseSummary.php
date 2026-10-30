<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Collection;

class AttendanceResponseSummary
{
    public function gathering(Collection $records, array $studentIds, string $type, ?array $range = null): array
    {
        $rows = $records->where('gathering_type', $type)
            ->filter(fn ($record) => $range === null
                || (($record->reporting_day ?? $record->day)->toDateString() >= $range['start']
                    && ($record->reporting_day ?? $record->day)->toDateString() <= $range['end']))
            ->filter(fn ($record) => in_array((int) $record->student_id, $studentIds, true));
        $responded = $rows->filter(fn ($record) => in_array($record->status ?? 'no_response', ['present', 'excused'], true))
            ->pluck('student_id')->unique()->count();
        $total = count($studentIds);

        return [
            'status' => match (true) {
                $responded === 0 => 'no_report',
                $total > 0 && $responded >= $total => 'completed',
                default => 'incomplete',
            },
            'has_attendance' => $rows->isNotEmpty(),
            'responded_students' => min($responded, $total),
            'total_students' => $total,
        ];
    }
}
