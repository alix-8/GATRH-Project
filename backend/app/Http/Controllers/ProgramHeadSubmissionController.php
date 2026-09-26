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
            'status' => ['nullable', 'in:pending,approved,rejected'],
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

        $pendingCount = $records->where('status', 'pending')->count();

        if (!empty($filters['date'])) {
            $records = $records->where('date_iso', $filters['date'])->values();
        }
        if (!empty($filters['gathering_type'])) {
            $records = $records->where('gathering_type', $filters['gathering_type'])->values();
        }
        if (!empty($filters['year_level'])) {
            $records = $records->where('year_level', (int) $filters['year_level'])->values();
        }
        if (!empty($filters['status'])) {
            $records = $records->where('status', $filters['status'])->values();
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
