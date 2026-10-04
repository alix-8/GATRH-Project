<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;

use App\Models\AttendanceDraft;
use App\Models\AttendanceRecord;
use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Student::query()->accessibleTo($request->user())->get()
        );
    }
    public function attendanceSnapshot(Request $request)
    {
        $user = $request->user();
        $students = Student::query()
            ->accessibleTo($user)
            ->with('escalation')
            ->orderBy('name')
            ->get();

        $responses = collect();
        $session = null;

        $draft = AttendanceDraft::query()
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->orderByDesc('day')
            ->orderByDesc('updated_at')
            ->first();

        if ($draft) {
            $responses = collect($draft->parsed_data ?? [])
                ->keyBy(fn (array $record) => $record['student_id'] ?? null);
            $session = [
                'source' => 'draft',
                'gathering_type' => $draft->gathering_type,
                'day' => optional($draft->day)->toDateString(),
            ];
        } else {
            $latestRecord = AttendanceRecord::query()
                ->whereIn('student_id', $students->pluck('id'))
                ->orderByDesc('day')
                ->orderByDesc('created_at')
                ->first();

            if ($latestRecord) {
                $records = AttendanceRecord::query()
                    ->whereIn('student_id', $students->pluck('id'))
                    ->where('gathering_type', $latestRecord->gathering_type)
                    ->whereDate('day', $latestRecord->day)
                    ->get();

                $responses = $records->keyBy('student_id');
                $session = [
                    'source' => 'submitted',
                    'gathering_type' => $latestRecord->gathering_type,
                    'day' => $latestRecord->day->toDateString(),
                ];
            }
        }

        return response()->json([
            'students' => $students->map(function (Student $student) use ($responses, $draft, $session) {
                $record = $responses->get($student->id);
                $hasResponded = $record && ($record['status'] ?? $record->status) === 'present';
                $draftNoResponse = $draft && $record && ($record['status'] ?? null) === 'no_response' ? 1 : 0;

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'program' => $student->program,
                    'year_level' => $student->year_level,
                    'response' => $session ? ($hasResponded ? 'Responded' : 'No Response') : null,
                    'escalation_level' => $student->escalation->escalation_level ?? 'None',
                    'no_response_count' => ($student->escalation->no_response_count ?? 0) + $draftNoResponse,
                ];
            })->values(),
            'session' => $session,
        ]);
    }
    /** Return the selected student's attendance details for the overview drawer. */
    public function overview(Request $request, int $id)
    {
        $student = Student::query()
            ->accessibleTo($request->user())
            ->with('escalation')
            ->findOrFail($id);

        $history = $student->attendanceRecords()
            ->orderByDesc('day')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AttendanceRecord $record) => [
                'id' => 'record-' . $record->id,
                'gathering_type' => $record->gathering_type,
                'gathering' => $this->gatheringLabel($record->gathering_type),
                'time' => $record->batch ? substr($record->batch, 0, 5) : '—',
                'date' => $record->day->toDateString(),
                'location' => $record->venue ?: '—',
                'status' => $record->status,
            ]);

        // The latest draft is included so a secretary can see the import right
        // away, before the attendance batch is submitted.
        $draft = AttendanceDraft::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->orderByDesc('day')
            ->orderByDesc('updated_at')
            ->first();

        if ($draft) {
            $draftRecord = collect($draft->parsed_data ?? [])
                ->first(fn (array $record) => (int) ($record['student_id'] ?? 0) === $student->id);

            if ($draftRecord) {
                $history->prepend([
                    'id' => 'draft-' . $draft->id,
                    'gathering_type' => $draft->gathering_type,
                    'gathering' => $this->gatheringLabel($draft->gathering_type),
                    'time' => $draftRecord['batch'] ?? '—',
                    'date' => $draftRecord['day'] ?? optional($draft->day)->toDateString(),
                    'location' => $draftRecord['venue'] ?? '—',
                    'status' => $draftRecord['status'] ?? 'no_response',
                ]);
            }
        }

        $history = $history->sortByDesc('date')->values();
        $referenceDate = $history->first()['date'] ?? now()->toDateString();
        $monthPrefix = substr($referenceDate, 0, 7);
        $thisMonth = $history->filter(fn (array $record) => str_starts_with($record['date'] ?? '', $monthPrefix))->count();

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'program' => $student->program,
                'year_level' => $student->year_level,
                'no_response_count' => $student->escalation->no_response_count ?? 0,
            ],
            'month' => date('F Y', strtotime($referenceDate)),
            'summary' => [
                'this_month' => $thisMonth,
                'attendance_count' => $history->count(),
                'escalation_count' => $student->escalation->no_response_count ?? 0,
            ],
            'history' => $history,
        ]);
    }

    private function gatheringLabel(?string $type): string
    {
        return match ($type) {
            'pm' => 'Prayer Meeting',
            'ws' => 'Worship Service',
            'tg' => 'Thanksgiving',
            'special' => 'Special Gathering',
            default => 'Gathering',
        };
    }
}