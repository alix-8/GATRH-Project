<?php

namespace App\Services\Escalations;

use App\Models\AttendanceRecord;
use App\Models\AttendanceDraft;
use App\Models\Escalation;
use App\Support\SystemTimezone;
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

        $now = SystemTimezone::now();
        $start = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $end = $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $noResponseGatherings = AttendanceRecord::query()
            ->whereIn('student_id', $ids)
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])
            ->whereBetween('day', [$start, $end])
            ->where('status', 'no_response')
            ->get(['student_id', 'gathering_type'])
            ->map(fn (AttendanceRecord $record) => $record->student_id . ':' . $record->gathering_type)
            ->all();

        AttendanceDraft::query()
            ->where('status', 'draft')
            ->whereBetween('day', [$start, $end])
            ->get(['gathering_type', 'parsed_data'])
            ->each(function (AttendanceDraft $draft) use (&$noResponseGatherings, $ids) {
                foreach ($draft->parsed_data ?? [] as $record) {
                    $studentId = (int) ($record['student_id'] ?? 0);
                    $type = $draft->gathering_type;

                    if ($studentId && $ids->contains($studentId)
                        && in_array($type, ['pm', 'ws', 'tg'], true)
                        && ($record['status'] ?? 'no_response') === 'no_response') {
                        $noResponseGatherings[] = $studentId . ':' . $type;
                    }
                }
            });

        return collect($noResponseGatherings)
            ->unique()
            ->map(fn (string $key) => explode(':', $key, 2))
            ->groupBy(fn (array $key) => $key[0])
            ->map(fn (Collection $gatherings) => min(3, $gatherings->pluck(1)->unique()->count()));
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

    public function syncDraft(AttendanceDraft $draft, ?array $previousData = null): void
    {
        $studentIds = collect($previousData ?? [])
            ->merge($draft->parsed_data ?? [])
            ->pluck('student_id')
            ->filter()
            ->unique();

        foreach ($studentIds as $studentId) {
            $this->syncStudent((int) $studentId);
        }
    }

    public function syncAll(): int
    {
        $now = SystemTimezone::now();
        $start = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $end = $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $studentIds = Escalation::query()->pluck('student_id')
            ->merge(AttendanceRecord::query()->distinct()->pluck('student_id'))
            ->merge(AttendanceDraft::query()->where('status', 'draft')
                ->whereBetween('day', [$start, $end])
                ->get(['parsed_data'])
                ->flatMap(fn (AttendanceDraft $draft) => collect($draft->parsed_data ?? [])->pluck('student_id')))
            ->unique();

        foreach ($studentIds as $studentId) {
            $this->syncStudent((int) $studentId);
        }

        return $studentIds->count();
    }
}
