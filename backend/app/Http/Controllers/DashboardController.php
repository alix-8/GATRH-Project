<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Display the dashboard payload for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $filter = $request->input('range', 'this_week');
        $dateRange = $this->resolveDateRange($filter);
        $calendarMonth = $request->integer('month', now()->month);
        $calendarYear = $request->integer('year', now()->year);

        return response()->json([
            'summary' => $this->getAttendanceSummary($user, $dateRange),
            'chart_data' => $this->getAttendanceChartData($user, $filter, $dateRange),
            'pending_list' => $this->getPendingAttendance($user),
            'recent_records' => $this->getRecentRecords($user, $dateRange),
            'calendar_marks' => $this->getCalendarMarks($user, $calendarMonth, $calendarYear),
        ]);
    }

    /**
     * 1. Top summary cards for presents and no-response records.
     */
    private function getAttendanceSummary($user, array $dateRange): array
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

    /**
     * 2. Attendance grouped by the actual gathering types in this project.
     */
    private function getAttendanceChartData($user, string $filter, array $dateRange): array
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

    /**
     * 3. Recent draft submissions for the current user (Secretary).
     */
    private function getPendingAttendance($user): array
    {
        return AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'pending'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($draft) {
                $parsed = is_array($draft->parsed_data) ? $draft->parsed_data : (array) $draft->parsed_data;
                $firstRecord = collect($parsed)->first();
                $gatheringType = $firstRecord['gathering_type'] ?? null;

                return [
                    'id' => $draft->id,
                    'program_label' => $firstRecord['program'] ?? 'Attendance Draft',
                    'gathering' => $this->formatGatheringName($gatheringType ?? 'pm'),
                    'submitted_ago' => $draft->created_at->diffForHumans(),
                ];
            })
            ->toArray();
    }

    /**
     * 4. Recent attendance records and response ratio.
     */
    private function getRecentRecords($user, array $dateRange): array
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

    /**
     * 5. Calendar marks for attendance records and active drafts in the current month.
     */
    private function getCalendarMarks($user, int $monthNumber, int $year): array
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

    /**
     * Resolve the date range for the selected dashboard filter.
     */
    private function resolveDateRange(string $filter): array
    {
        return match ($filter) {
            'today' => ['start' => now()->startOfDay(), 'end' => now()->endOfDay()],
            'this_month' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
            'last_week' => ['start' => now()->subWeek()->startOfWeek(), 'end' => now()->subWeek()->endOfWeek()],
            default => ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()],
        };
    }

    /**
     * Map the system gathering codes to readable names.
     */
    private function formatGatheringName(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanks Giving',
            default => $type ?? 'Unknown',
        };
    }
}