<?php

namespace App\Services\Dashboard;

use App\Models\{AttendanceDraft, AttendanceRecord, Student, User};
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Read-once data for one dashboard response. Nothing persists between requests. */
class SecretaryDashboardData
{
    public readonly int $studentCount;
    public readonly Collection $drafts;
    private Collection $records;
    private Collection $entries;
    private array $counts = [];

    public function __construct(User $user, array $ranges)
    {
        $this->studentCount = Student::active()->accessibleTo($user)->count();
        $this->drafts = AttendanceDraft::where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->orderByDesc('updated_at')
            ->get(['id', 'gathering_type', 'day', 'status', 'parsed_data', 'matched_count', 'unsubmitted_at', 'updated_at']);
        $this->entries = $this->drafts->mapWithKeys(fn ($draft) => [$draft->id => collect($draft->parsed_data ?? [])]);
        $this->records = AttendanceRecord::query()
            ->whereHas('student', fn ($students) => $students->accessibleTo($user))
            ->where(function ($query) use ($ranges) {
                foreach ($ranges as $range) {
                    $query->orWhere(fn ($period) => $period->inReportingRange([$range['start'], $range['end']]));
                }
            })
            ->get(['student_id', 'gathering_type', 'status', 'day', 'reporting_day'])->toBase();
    }

    public function recordsIn(array $range): Collection
    {
        return $this->within($this->records, $range, true);
    }

    public function draftsIn(array $range): Collection
    {
        return $this->within($this->drafts, $range);
    }

    public function entriesFor(AttendanceDraft $draft): Collection
    {
        return $this->entries->get($draft->id, collect());
    }

    public function statusCounts(array $range): array
    {
        $key = Carbon::parse($range['start'])->toDateString().'|'.Carbon::parse($range['end'])->toDateString();
        return $this->counts[$key] ??= $this->recordsIn($range)->countBy('status')->all();
    }

    private function within(Collection $rows, array $range, bool $reportingDate = false): Collection
    {
        $start = Carbon::parse($range['start'])->toDateString();
        $end = Carbon::parse($range['end'])->toDateString();
        return $rows->filter(function ($row) use ($start, $end, $reportingDate) {
            $day = ($reportingDate ? ($row->reporting_day ?? $row->day) : $row->day)?->toDateString();
            return $day !== null && $day >= $start && $day <= $end;
        });
    }
}
