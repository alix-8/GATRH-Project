<?php

namespace App\Http\Controllers;

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
            'students' => $students->map(function (Student $student) use ($responses, $draft) {
                $record = $responses->get($student->id);
                $hasResponded = $record && ($record['status'] ?? $record->status) === 'present';
                $draftNoResponse = $draft && $record && ($record['status'] ?? null) === 'no_response' ? 1 : 0;

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'program' => $student->program,
                    'year_level' => $student->year_level,
                    'response' => $hasResponded ? 'Responded' : 'No Response',
                    'escalation_level' => $student->escalation->escalation_level ?? 'None',
                    'no_response_count' => ($student->escalation->no_response_count ?? 0) + $draftNoResponse,
                ];
            })->values(),
            'session' => $session,
        ]);
    }

    // //Add student
    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'name' => 'required|string|max:255',
    //         'program' => 'required|string',
    //         'year_level' => 'required|string',
    //     ]);

    //     $student = Student::create($validated);

    //     // Auto-initialize escalation record
    //     $student->escalation()->create([
    //         'no_response_count' => 0,
    //         'escalation_level' => 'None',
    //     ]);

    //     return response()->json($student, 201);
    // }
}