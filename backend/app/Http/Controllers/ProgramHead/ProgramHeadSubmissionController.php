<?php

namespace App\Http\Controllers\ProgramHead;

use App\Http\Controllers\Controller;

use App\Models\AttendanceRecord;
use App\Models\Program;
use App\Services\ProgramHead\ProgramHeadAttendanceWorkbookService;
use App\Services\ProgramHead\ProgramHeadRecordsExportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProgramHeadSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'gathering_type' => ['nullable', 'in:pm,ws,tg'],
            'year_level' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
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
                $status = $group->every(fn ($record) => $record->validation_status === 'validated') ? 'approved' : 'pending';

                return [
                    'id' => (string) $key,
                    'secretary' => $first->submitter?->name ?? 'Unknown Secretary',
                    'gathering' => $this->gatheringLabel($first->gathering_type),
                    'gathering_type' => $first->gathering_type,
                    'program' => $first->program ?? $first->student?->program ?? 'N/A',
                    'year_level' => $first->year_level ?? $first->student?->year_level,
                    'year_label' => $this->ordinalYear($first->year_level ?? $first->student?->year_level),
                    'date' => Carbon::parse($first->day)->format('M j, Y'),
                    'date_iso' => Carbon::parse($first->day)->toDateString(),
                    'status' => $status,
                    'status_label' => $status === 'approved' ? 'Approved' : 'Pending',
                    'student_count' => $group->count(),
                    '_search_text' => $group->pluck('student.name')->filter()->implode(' '),
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
                $record['date'], $record['status_label'], $record['_search_text'],
            ])), $needle))->values();
        }

        $total = $records->count();
        $all = (bool) ($filters['all'] ?? false);
        $page = $all ? 1 : (int) ($filters['page'] ?? 1);
        $perPage = $all ? max(1, $total) : (int) ($filters['per_page'] ?? 5);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return response()->json([
            'pending_count' => $pendingCount,
            'data' => $records->slice(($page - 1) * $perPage, $perPage)
                ->map(function (array $record) {
                    unset($record['_search_text']);
                    return $record;
                })
                ->values(),
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
            'gathering_type' => ['nullable', 'in:pm,ws,tg'],
            'year_level' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
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
                $record->program ?? $record->student?->program ?? 'N/A',
                $record->year_level ?? $record->student?->year_level ?? 0,
            ]))
            ->map(function ($group, $key) {
                $first = $group->first();
                $year = (int) ($first->year_level ?? $first->student?->year_level ?? 0);
                return [
                    'id' => $key,
                    'batch_key' => $first->batch_id ?: implode('|', [$first->gathering_type, Carbon::parse($first->day)->toDateString(), $first->submitted_by]),
                    'secretary' => $first->submitter?->name ?? 'Unknown Secretary',
                    'gathering' => $this->gatheringLabel($first->gathering_type),
                    'gathering_type' => $first->gathering_type,
                    'program' => $first->program ?? $first->student?->program ?? 'N/A',
                    'year_level' => $year,
                    'year_label' => $this->ordinalYear($year),
                    'date' => Carbon::parse($first->day)->format('M j, Y'),
                    'date_iso' => Carbon::parse($first->day)->toDateString(),
                    'status' => 'approved',
                    'status_label' => 'Approved',
                    'student_count' => $group->count(),
                    '_search_text' => $group->pluck('student.name')->filter()->implode(' '),
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
                $record['date'], $record['status_label'], $record['_search_text'],
            ])), $needle))->values();
        }

        $total = $groups->count();
        $all = (bool) ($filters['all'] ?? false);
        $page = $all ? 1 : (int) ($filters['page'] ?? 1);
        $perPage = $all ? max(1, $total) : (int) ($filters['per_page'] ?? 10);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        return response()->json([
            'data' => $groups->slice(($page - 1) * $perPage, $perPage)
                ->map(function (array $record) {
                    unset($record['_search_text']);
                    return $record;
                })
                ->values(),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    public function exportRecords(
        Request $request,
        ProgramHeadRecordsExportService $exportService,
        ProgramHeadAttendanceWorkbookService $workbookService
    ) {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'gathering_type' => ['nullable', 'in:pm,ws,tg'],
            'year_level' => ['nullable', 'integer', 'between:1,4'],
            'search' => ['nullable', 'string', 'max:100'],
            'batch_key' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'in:session,week,month'],
            'program' => ['required_if:period,week,month', 'nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'integer', 'between:1,6', 'required_with:month'],
        ]);

        $period = $filters['period'] ?? 'session';

        if ($period !== 'session') {
            $periodFilters = $request->validate([
                'month' => ['required_without:date', 'date_format:Y-m'],
                'date' => ['required_without:month', 'date'],
                'year_level' => ['required', 'integer', 'between:1,4'],
            ]);
            $selectedDate = !empty($periodFilters['month'])
                ? CarbonImmutable::createFromFormat('!Y-m', $periodFilters['month'])
                : CarbonImmutable::parse($periodFilters['date']);
            $selectedWeek = $period === 'week' ? (int) $filters['week'] : null;
            $program = strtoupper(trim($filters['program']));
            $yearLevel = (int) $periodFilters['year_level'];
            [$start, $end] = $workbookService->periodBounds($selectedDate, $period, $selectedWeek);
            $students = $exportService->periodStudents(
                $request->user(),
                $program,
                $yearLevel,
                $start,
                $end
            );
            $programName = Program::query()->where('code', $program)->value('name');
            $workbookPath = $workbookService->createPeriod(
                $students,
                $selectedDate,
                $period,
                $program,
                $yearLevel,
                $programName,
                $selectedWeek
            );
            $filename = sprintf(
                '%s-attendance-%s-%s-to-%s.xlsx',
                $period === 'week' ? 'weekly' : 'monthly',
                Str::slug($program),
                $start->toDateString(),
                $end->toDateString()
            );
        } else {
            $records = $exportService->query($request->user(), $filters)->get();
            $workbookPath = $workbookService->create($records);
            $filename = 'approved-attendance-records.' . $workbookService->fileExtension();
        }

        return response()
            ->download(
                $workbookPath,
                $filename,
                [
                    'Content-Type' => $workbookService->mimeType(),
                    'Access-Control-Expose-Headers' => 'Content-Disposition',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    private function gatheringLabel(?string $type): string
    {
        return match ($type) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanksgiving',
            default => ucfirst((string) $type),
        };
    }

    private function ordinalYear(?int $year): string
    {
        if (!$year) {
            return 'N/A';
        }

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
