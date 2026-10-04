<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;

use App\Models\AttendanceDraft;
use App\Models\Reminder;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AttendanceDraftController extends Controller
{
    /**
     * Resume an existing draft by ID.
     * GET /api/attendance/drafts/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $draft = AttendanceDraft::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Draft not found or already submitted.',
            ], 404);
        }

        $submissionDay = optional($draft->day)->toDateString();

        $students = collect($draft->parsed_data)->map(
            function ($record) use ($submissionDay) {
                return [
                    'student_id'   => $record['student_id'] ?? null,
                    'student_name' => $record['student_name'] ?? 'Unknown',
                    'status'       => $record['status'] ?? 'no_response',
                    'batch'        => !empty($record['batch'])
                        ? \Carbon\Carbon::parse($record['batch'])->format('g:ia')
                        : '---',
                    'venue'        => $record['venue'] ?? '---',

                    // For absent/no-response students, use the gathering submission date.
                    'day'          => $record['day'] ?? $submissionDay ?? '---',
                ];
            }
        );

        $responded = $students->where('status', 'present')->count();
        $total = $students->count();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'                 => $draft->id,
                'gathering_type'     => $draft->gathering_type,
                'day'                => optional($draft->day)->format('F j, Y'),
                'day_iso'            => optional($draft->day)->toDateString(),
                'submitted_by'       => [
                    'name' => $request->user()->name,
                    'role' => trim(strtoupper($request->user()->program) . ' ' . $request->user()->year_level . 'rd Year · Spiritual Secretary'),
                ],
                'total_students'     => $total,
                'responded_count'    => $responded,
                'no_response_count'  => $total - $responded,
                'students'           => $students,
            ],
        ], 200);
    }

    /**
     * Submit a draft: Move valid entries to final attendance records and clear/mark draft.
     * POST /api/attendance/drafts/{id}/submit
     */
    // public function submit(Request $request, int $id): JsonResponse
    // {
    //     $draft = AttendanceDraft::where('id', $id)
    //         ->where('user_id', $request->user()->id)
    //         ->where('status', 'draft')
    //         ->first();

    //     if (!$draft) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Draft not found or already processed.',
    //         ], 404);
    //     }

    //     $studentIds = collect($draft->parsed_data)
    //         ->pluck('student_id')
    //         ->filter()
    //         ->unique();
    //     $accessibleStudentCount = Student::whereKey($studentIds)
    //         ->accessibleTo($request->user())
    //         ->count();

    //     if ($accessibleStudentCount !== $studentIds->count()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'You are not authorized to submit attendance for one or more students.',
    //         ], 403);
    //     }

    //     DB::transaction(function () use ($draft, $request) {
    //         // 1. Insert parsed data into official attendance table (adjust table/model name as needed)
    //         foreach ($draft->parsed_data as $record) {
    //             DB::table('attendance_records')->insert([
    //                 'user_id'      => $request->user()->id,
    //                 'gathering_id' => $draft->gathering_id,
    //                 'student_id'   => $record['student_id'] ?? null,
    //                 'status'       => $record['status'] ?? 'present',
    //                 'created_at'   => now(),
    //                 'updated_at'   => now(),
    //             ]);
    //         }

    //         // 2. Mark draft as submitted
    //         $draft->update(['status' => 'submitted']);

    //         // 3. Update related submission reminder status if applicable
    //         if ($draft->gathering_id) {
    //             Reminder::where('user_id', $request->user()->id)
    //                 ->where('gathering_id', $draft->gathering_id)
    //                 ->where('type', 'submission_deadline')
    //                 ->update(['status' => 'completed']);
    //         }
    //     });

    //     return response()->json([
    //         'status'  => 'success',
    //         'message' => 'Attendance record submitted successfully.',
    //     ], 200);
    // }

        public function destroyMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $deleted = AttendanceDraft::whereIn('id', $validated['ids'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->delete();

        return response()->json([
            'status'  => 'success',
            'message' => "{$deleted} draft(s) deleted.",
            'deleted' => $deleted,
        ], 200);
    }
}

