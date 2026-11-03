<?php

namespace App\Services\Escalations;

use App\Models\AttendanceRecord;
use App\Models\AttendanceDraft;
use App\Models\Escalation;
use App\Support\GatheringCycle;
use App\Support\SystemTimezone;
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
        $start = GatheringCycle::weekStartForDate($now)->toDateString();
        $end = GatheringCycle::weekEndForDate($now)->toDateString();

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

    /**
     * Return the distinct current-week no-response gathering entries that
     * contribute to one student's escalation level. Saved attendance takes
     * precedence over a draft for the same gathering type.
     */
    public function evidenceForStudent(int $studentId, bool $includeDrafts = true, ?int $draftOwnerId = null): Collection
    {
        $now = SystemTimezone::now();
        $start = GatheringCycle::weekStartForDate($now)->toDateString();
        $end = GatheringCycle::weekEndForDate($now)->toDateString();
        $evidenceByGathering = [];

        AttendanceRecord::query()
            ->where('student_id', $studentId)
            ->whereIn('gathering_type', ['pm', 'ws', 'tg'])
            ->whereBetween('day', [$start, $end])
            ->where('status', 'no_response')
            ->orderByDesc('created_at')
            ->get(['id', 'gathering_type', 'day', 'batch', 'venue'])
            ->each(function (AttendanceRecord $record) use (&$evidenceByGathering): void {
                if (isset($evidenceByGathering[$record->gathering_type])) {
                    return;
                }

                $evidenceByGathering[$record->gathering_type] = [
                    'id' => 'record-' . $record->id,
                    'gathering_type' => $record->gathering_type,
                    'day' => $record->day->toDateString(),
                    'time' => $record->batch ? substr($record->batch, 0, 5) : '—',
                    'location' => $record->venue ?: '—',
                    'source' => 'submitted',
                ];
            });

        if ($includeDrafts) {
            AttendanceDraft::query()
                ->where('status', 'draft')
                ->when($draftOwnerId, fn ($query) => $query->where('user_id', $draftOwnerId))
                ->whereBetween('day', [$start, $end])
                ->orderByDesc('updated_at')
                ->get(['id', 'gathering_type', 'day', 'parsed_data'])
                ->each(function (AttendanceDraft $draft) use ($studentId, &$evidenceByGathering): void {
                    $type = $draft->gathering_type;
                    $entry = collect($draft->parsed_data ?? [])->first(fn (array $record) =>
                        (int) ($record['student_id'] ?? 0) === $studentId
                        && ($record['status'] ?? 'no_response') === 'no_response'
                    );

                    if (! $entry || ! in_array($type, ['pm', 'ws', 'tg'], true) || isset($evidenceByGathering[$type])) {
                        return;
                    }

                    $evidenceByGathering[$type] = [
                        'id' => 'draft-' . $draft->id,
                        'gathering_type' => $type,
                        'day' => $entry['day'] ?? $draft->day->toDateString(),
                        'time' => $entry['batch'] ?? '—',
                        'location' => $entry['venue'] ?? '—',
                        'source' => 'draft',
                    ];
                });
        }

        return collect(['pm', 'ws', 'tg'])
            ->map(fn (string $type) => $evidenceByGathering[$type] ?? null)
            ->filter()
            ->values();
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
        $start = GatheringCycle::weekStartForDate($now)->toDateString();
        $end = GatheringCycle::weekEndForDate($now)->toDateString();
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
