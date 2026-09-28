<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramHeadSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'gathering_type' => ['nullable', 'in:pm,ws,tg,special'],
            'year_level' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $user = $request->user();
        $programCodes = $user->assignedProgramCodes();

        $records = AttendanceRecord::query()
            ->with(['student', 'submitter'])
            ->whereHas('student', function ($query) use ($user, $programCodes) {
                $query->accessibleTo($user);
                if ($user->role === 'program_head') {
                    $query->whereIn('program', $programCodes);
                }
            })
            ->get()
            ->groupBy(fn (AttendanceRecord $record) => $record->batch_id
                ?: implode('|', [
                    $record->gathering_type,
                    Carbon::parse($record->day)->toDateString(),
                    $record->submitted_by,
                ]))
            ->map(function ($group, $key) {
                $first = $group->first();
                $status = $group->contains('validation_status', 'rejected')
                    ? 'rejected'
                    : ($group->every(fn ($record) => $record->validation_status === 'validated') ? 'approved' : 'pending');

                return [
                    'id' => (string) $key,
                    'secretary' => $first->submitter?->name ?? 'Unknown Secretary',
                    'gathering' => $this->gatheringLabel($first->gathering_type),
                    'gathering_type' => $first->gathering_type,
                    'program' => $first->student?->program ?? 'N/A',
                    'year_level' => $first->student?->year_level,
                    'year_label' => $this->ordinalYear($first->student?->year_level),
                    'date' => Carbon::parse($first->day)->format('M j, Y'),
                    'date_iso' => Carbon::parse($first->day)->toDateString(),
                    'status' => $status,
                    'status_label' => ucfirst($status),
                    'student_count' => $group->count(),
                ];
            })
            ->sortByDesc('date_iso')
            ->values();

        $records = $records->where('status', 'pending')->values();
        $pendingCount = $records->count();

        if (!empty($filters['date'])) {
            $records = $records->where('date_iso', $filters['date'])->values();
        }
        if (!empty($filters['gathering_type'])) {
            $records = $records->where('gathering_type', $filters['gathering_type'])->values();
        }
        if (!empty($filters['year_level'])) {
            $records = $records->where('year_level', (int) $filters['year_level'])->values();
        }
        if (!empty($filters['search'])) {
            $needle = mb_strtolower(trim($filters['search']));
            $records = $records->filter(fn (array $record) => str_contains(mb_strtolower(implode(' ', [
                $record['secretary'], $record['gathering'], $record['program'], $record['year_label'],
            ])), $needle))->values();
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 5);
        $total = $records->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return response()->json([
            'pending_count' => $pendingCount,
            'data' => $records->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'gathering_type' => ['nullable', 'in:pm,ws,tg,special'],
            'year_level' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $user = $request->user();
        $groups = AttendanceRecord::query()
            ->with(['student', 'submitter'])
            ->where('validation_status', 'validated')
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->get()
            ->groupBy(fn (AttendanceRecord $record) => implode('|', [
                $record->batch_id ?: implode(':', [$record->gathering_type, Carbon::parse($record->day)->toDateString(), $record->submitted_by]),
                $record->student?->program ?? 'N/A',
                $record->student?->year_level ?? 0,
            ]))
            ->map(function ($group, $key) {
                $first = $group->first();
                $year = (int) ($first->student?->year_level ?? 0);
                return [
                    'id' => $key,
                    'batch_key' => $first->batch_id ?: implode('|', [$first->gathering_type, Carbon::parse($first->day)->toDateString(), $first->submitted_by]),
                    'secretary' => $first->submitter?->name ?? 'Unknown Secretary',
                    'gathering' => $this->gatheringLabel($first->gathering_type),
                    'gathering_type' => $first->gathering_type,
                    'program' => $first->student?->program ?? 'N/A',
                    'year_level' => $year,
                    'year_label' => $this->ordinalYear($year),
                    'date' => Carbon::parse($first->day)->format('M j, Y'),
                    'date_iso' => Carbon::parse($first->day)->toDateString(),
                    'status' => 'approved',
                    'status_label' => 'Approved',
                    'student_count' => $group->count(),
                ];
            })
            ->sortByDesc('date_iso')->values();

        if (!empty($filters['date'])) $groups = $groups->where('date_iso', $filters['date'])->values();
        if (!empty($filters['gathering_type'])) $groups = $groups->where('gathering_type', $filters['gathering_type'])->values();
        if (!empty($filters['year_level'])) $groups = $groups->where('year_level', (int) $filters['year_level'])->values();
        if (!empty($filters['search'])) {
            $needle = mb_strtolower(trim($filters['search']));
            $groups = $groups->filter(fn (array $record) => str_contains(mb_strtolower(implode(' ', [
                $record['secretary'], $record['gathering'], $record['program'], $record['year_label'],
            ])), $needle))->values();
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        return response()->json([
            'data' => $groups->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    public function exportRecords(Request $request)
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'gathering_type' => ['nullable', 'in:pm,ws,tg,special'],
            'year_level' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:100'],
            'batch_key' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $query = AttendanceRecord::query()->with(['student', 'submitter'])
            ->where('validation_status', 'validated')
            ->whereHas('student', function ($builder) use ($user, $filters) {
                $builder->accessibleTo($user);
                if (!empty($filters['year_level'])) $builder->where('year_level', (int) $filters['year_level']);
            });
        if (!empty($filters['date'])) $query->whereDate('day', $filters['date']);
        if (!empty($filters['gathering_type'])) $query->where('gathering_type', $filters['gathering_type']);
        if (!empty($filters['batch_key'])) {
            $batchKey = $filters['batch_key'];
            if (str_contains($batchKey, '|')) {
                [$type, $day, $submitter] = explode('|', $batchKey, 3);
                $query->where('gathering_type', $type)->whereDate('day', $day)->where('submitted_by', $submitter);
            } else {
                $query->where('batch_id', $batchKey);
            }
        }
        if (!empty($filters['search'])) {
            $term = mb_strtolower(trim($filters['search']));
            $gatheringTypes = collect(['pm' => 'prayer meeting', 'ws' => 'worship service', 'tg' => 'thanksgiving', 'special' => 'special gathering']);
            $matchingTypes = $gatheringTypes->filter(fn ($label) => str_contains($label, $term))->keys()->all();
            $query->where(function ($builder) use ($term, $matchingTypes) {
                $builder->whereHas('student', fn ($student) => $student->where('name', 'like', "%{$term}%")
                    ->orWhere('program', 'like', "%{$term}%"))
                    ->orWhereHas('submitter', fn ($submitter) => $submitter->where('name', 'like', "%{$term}%"));
                if ($matchingTypes) $builder->orWhereIn('gathering_type', $matchingTypes);
            });
        }
        $records = $query->orderByDesc('day')->get();
        $callback = function () use ($records) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Secretary', 'Gathering', 'Program', 'Year', 'Date', 'Student', 'Time', 'Location', 'Attendance Status']);
            foreach ($records as $record) {
                fputcsv($handle, [
                    $record->submitter?->name ?? 'Unknown Secretary', $this->gatheringLabel($record->gathering_type),
                    $record->student?->program ?? 'N/A', $this->ordinalYear($record->student?->year_level),
                    Carbon::parse($record->day)->format('Y-m-d'), $record->student?->name ?? 'Unknown',
                    $record->batch ? Carbon::parse($record->batch)->format('g:i A') : '', $record->venue ?? '',
                    $record->status === 'present' ? 'Present' : 'No Response',
                ]);
            }
            fclose($handle);
        };
        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="approved-attendance-records.csv"',
        ]);
    }

    private function gatheringLabel(?string $type): string
    {
        return match ($type) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanksgiving',
            'special' => 'Special Gathering',
            default => ucfirst((string) $type),
        };
    }

    private function ordinalYear(?int $year): string
    {
        if (!$year) return 'N/A';
        $suffix = match (true) {
            $year % 100 >= 11 && $year % 100 <= 13 => 'th',
            $year % 10 === 1 => 'st',
            $year % 10 === 2 => 'nd',
            $year % 10 === 3 => 'rd',
            default => 'th',
        };
        return "{$year}{$suffix} Year";
    }
}
