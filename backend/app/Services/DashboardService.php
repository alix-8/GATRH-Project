<?php

namespace App\Services;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getDashboardPayload($user, string $filter, int $calendarMonth, int $calendarYear): array
    {
        $dateRange = $this->resolveDateRange($filter);

        if ($user->isProgramHead()) {
            return [
                'summary' => $this->getProgramHeadSummary($user, $dateRange),
                'chart_data' => $this->getAttendanceChartData($user, $filter, $dateRange),
                'pending_list' => $this->getProgramHeadPendingList($user, $dateRange),
                'recent_records' => $this->getProgramHeadRecentActivity($user, $dateRange),
                'calendar_marks' => $this->getCalendarMarks($user, $calendarMonth, $calendarYear),
                'role' => 'program_head',
            ];
        }

        return [
            'summary' => $this->getAttendanceSummary($user, $dateRange),
            'chart_data' => $this->getAttendanceChartData($user, $filter, $dateRange),
            'pending_list' => $this->getPendingAttendance($user),
            'recent_records' => $this->getRecentRecords($user, $dateRange),
            'calendar_marks' => $this->getCalendarMarks($user, $calendarMonth, $calendarYear),
            'role' => 'secretary',
        ];
    }

    public function getProgramHeadSummary($user, array $dateRange): array
    {
        $baseQuery = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']]);

        $pendingReview = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereNull('validation_status')
                    ->orWhere('validation_status', 'unvalidated');
            })
            ->count();

        $approved = (clone $baseQuery)->where('validation_status', 'approved')->count();
        $rejected = (clone $baseQuery)->where('validation_status', 'rejected')->count();
        $total = (clone $baseQuery)->count();

        return [
            'pending_review' => $pendingReview,
            'approved' => $approved,
            'rejected' => $rejected,
            'total_submissions' => $total,
            'pendingReview' => $pendingReview,
            'approvedCount' => $approved,
            'rejectedCount' => $rejected,
            'totalSubmissions' => $total,
        ];
    }

    public function getAttendanceSummary($user, array $dateRange): array
    {
        $baseQuery = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']]);

        $presents = (clone $baseQuery)->where('status', 'present')->count();
        $absents = (clone $baseQuery)->where('status', 'no_response')->count();

        return [
            'total_presents' => $presents,
            'total_absents' => $absents,
            'total_records' => $presents + $absents,
        ];
    }

    public function getAttendanceChartData($user, string $filter, array $dateRange): array
    {
        $records = AttendanceRecord::query()
            ->select('gathering_type', 'status', DB::raw('COUNT(*) as total'))
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->groupBy('gathering_type', 'status')
            ->get();

        $categories = ['pm', 'ws', 'tg'];
        $chartData = [];

        foreach ($categories as $cat) {
            $presentCount = (int) ($records->where('gathering_type', $cat)->where('status', 'present')->first()->total ?? 0);
            $absentCount = (int) ($records->where('gathering_type', $cat)->where('status', 'no_response')->first()->total ?? 0);

            $chartData[] = [
                'category' => strtoupper($cat),
                'present' => $presentCount,
                'absent' => $absentCount,
            ];
        }

        return [
            'filter_applied' => $filter,
            'series' => $chartData,
        ];
    }

    public function getPendingAttendance($user): array
    {
        return AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($draft) use ($user) {
                $parsed = is_array($draft->parsed_data) ? $draft->parsed_data : (array) $draft->parsed_data;
                $firstRecord = collect($parsed)->first();
                $gatheringType = $firstRecord['gathering_type'] ?? null;

                return [
                    'id' => $draft->id,
                    'program_label' => $firstRecord['program'] ?? $user->program ?? 'Attendance Draft',
                    'year_level' => $firstRecord['year_level'] ?? $user->year_level,
                    'gathering' => $this->formatGatheringName($gatheringType ?? 'pm'),
                    'submitted_ago' => $draft->created_at->diffForHumans(),
                ];
            })
            ->toArray();
    }

    public function getProgramHeadPendingList($user, array $dateRange): array
    {
        return AttendanceRecord::query()
            ->with(['student', 'submitter'])
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->where(function ($query) {
                $query->whereNull('validation_status')
                    ->orWhere('validation_status', 'unvalidated');
            })
            ->orderBy('day', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($record) {
                return [
                    'id' => $record->id,
                    'secretary' => $record->submitter?->name ?? 'Unknown Secretary',
                    'gathering' => $this->formatGatheringName($record->gathering_type),
                    'program' => $record->student?->program ?? 'N/A',
                    'year_level' => $record->student?->year_level ?? 'N/A',
                    'date' => Carbon::parse($record->day)->format('M d, Y'),
                    'status' => 'Pending',
                    'status_label' => 'Pending',
                    'action' => 'Review',
                    'review' => true,
                ];
            })
            ->toArray();
    }

    public function getRecentRecords($user, array $dateRange): array
    {
        $totalManagedStudents = Student::query()->accessibleTo($user)->count();

        $attendances = AttendanceRecord::query()
            ->select('gathering_type', 'day', DB::raw('COUNT(DISTINCT student_id) as present_count'))
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->where('status', 'present')
            ->groupBy('gathering_type', 'day')
            ->orderBy('day', 'desc')
            ->limit(5)
            ->get();

        return $attendances->map(function ($row) use ($totalManagedStudents) {
            return [
                'gathering' => $this->formatGatheringName($row->gathering_type),
                'date' => Carbon::parse($row->day)->format('M d'),
                'time' => 'N/A',
                'response' => "{$row->present_count}/{$totalManagedStudents}",
            ];
        })->toArray();
    }

    public function getProgramHeadRecentActivity($user, array $dateRange): array
    {
        return AttendanceRecord::query()
            ->with(['student', 'submitter'])
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$dateRange['start'], $dateRange['end']])
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($record) {
                $status = $record->validation_status ?? 'unvalidated';
                $label = match ($status) {
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    default => 'Pending review',
                };

                return [
                    'id' => $record->id,
                    'title' => $this->formatGatheringName($record->gathering_type) . ' attendance ' . strtolower($label),
                    'description' => ($record->submitter?->name ?? 'Unknown') . ' - ' . ($record->student?->program ?? 'Unknown'),
                    'time_ago' => $record->updated_at?->diffForHumans() ?? 'Recently',
                    'status' => $status,
                ];
            })
            ->toArray();
    }

    public function getCalendarMarks($user, int $monthNumber, int $year): array
    {
        $month = Carbon::create($year, $monthNumber, 1)->startOfDay();
        $monthStart = $month->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $recordDays = AttendanceRecord::query()
            ->whereHas('student', fn ($query) => $query->accessibleTo($user))
            ->whereBetween('day', [$monthStart, $monthEnd])
            ->distinct()
            ->pluck('day')
            ->map(fn ($date) => Carbon::parse($date)->day)
            ->unique()
            ->values();

        $pendingDays = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->get()
            ->flatMap(function ($draft) use ($monthStart, $monthEnd) {
                return collect($draft->parsed_data ?? [])
                    ->pluck('day')
                    ->filter(fn ($date) => $date >= $monthStart && $date <= $monthEnd)
                    ->map(fn ($date) => Carbon::parse($date)->day);
            })
            ->unique()
            ->values();

        $highlightDays = $recordDays
            ->map(fn ($day) => ['day' => $day, 'type' => 'has_records'])
            ->concat($pendingDays->map(fn ($day) => ['day' => $day, 'type' => 'pending']))
            ->values()
            ->toArray();

        return [
            'month' => $month->format('F Y'),
            'year' => $month->year,
            'days_in_month' => $month->daysInMonth,
            'starts_on' => $month->format('l'),
            'today' => now()->year === $year && now()->month === $monthNumber ? now()->day : null,
            'highlight_days' => $highlightDays,
        ];
    }

    public function resolveDateRange(string $filter): array
    {
        return match ($filter) {
            'today' => ['start' => now()->startOfDay(), 'end' => now()->endOfDay()],
            'this_month' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
            'last_week' => ['start' => now()->subWeek()->startOfWeek(), 'end' => now()->subWeek()->endOfWeek()],
            default => ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()],
        };
    }

    public function formatGatheringName(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanks Giving',
            default => $type ?? 'Unknown',
        };
    }
}