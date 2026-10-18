<?php

namespace App\Services\ProgramHead;

use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramHeadRecordsExportService
{
    private const GATHERING_LABELS = [
        'pm' => 'prayer meeting',
        'ws' => 'worship service',
        'tg' => 'thanksgiving',
    ];

    /** Build an approved-record query scoped to the signed-in Program Head. */
    public function query(User $user, array $filters): Builder
    {
        $query = AttendanceRecord::query()
            ->with(['student.escalation', 'submitter'])
            ->where('validation_status', 'validated')
            ->whereHas('student', function (Builder $students) use ($user, $filters) {
                $students->active()->accessibleTo($user);

                if (!empty($filters['year_level'])) {
                    $students->where('year_level', (int) $filters['year_level']);
                }
            });

        if (!empty($filters['date'])) {
            $query->whereDate('day', $filters['date']);
        }

        if (!empty($filters['gathering_type'])) {
            $query->where('gathering_type', $filters['gathering_type']);
        }

        if (!empty($filters['batch_key'])) {
            $this->applyBatchFilter($query, $filters['batch_key']);
        }

        if (!empty($filters['search'])) {
            $this->applySearchFilter($query, trim($filters['search']));
        }

        return $query
            ->orderByDesc('day')
            ->orderBy('gathering_type')
            ->orderBy('student_id');
    }

    /** Load the program roster and its approved attendance for one export period. */
    public function periodStudents(
        User $user,
        string $program,
        int $yearLevel,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): Collection {
        return Student::query()
            ->active()
            ->accessibleTo($user)
            ->where('program', $program)
            ->where('year_level', $yearLevel)
            ->with('escalation')
            ->with(['attendanceRecords' => function (HasMany $records) use ($start, $end) {
                $records
                    ->whereIn('validation_status', ['validated', 'approved'])
                    ->whereIn('gathering_type', ['ws', 'tg', 'pm'])
                    // Attendance slots span through the day after Wednesday for the PM session.
                    ->whereBetween('day', [$start->toDateString(), $end->addDay()->toDateString()])
                    ->orderBy('day')
                    ->orderBy('gathering_type');
            }])
            ->orderBy('name')
            ->get(['id', 'name', 'program', 'year_level']);
    }

    private function applyBatchFilter(Builder $query, string $batchKey): void
    {
        $parts = explode('|', $batchKey, 3);

        if (count($parts) === 3) {
            [$gatheringType, $date, $submitterId] = $parts;

            $query->where('gathering_type', $gatheringType)
                ->whereDate('day', $date)
                ->where('submitted_by', $submitterId);

            return;
        }

        $query->where('batch_id', $batchKey);
    }

    private function applySearchFilter(Builder $query, string $term): void
    {
        $normalizedTerm = mb_strtolower($term);
        $matchingGatherings = collect(self::GATHERING_LABELS)
            ->filter(fn (string $label) => str_contains($label, $normalizedTerm))
            ->keys()
            ->all();

        $query->where(function (Builder $records) use ($term, $matchingGatherings) {
            $records
                ->whereHas('student', function (Builder $students) use ($term) {
                    $students->where('name', 'like', "%{$term}%")
                        ->orWhere('program', 'like', "%{$term}%");
                })
                ->orWhereHas('submitter', fn (Builder $submitters) =>
                    $submitters->where('name', 'like', "%{$term}%")
                );

            if ($matchingGatherings !== []) {
                $records->orWhereIn('gathering_type', $matchingGatherings);
            }
        });
    }
}
