<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDraft;
use App\Models\Reminder;
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

        return response()->json([
            'status' => 'success',
            'data'   => $draft,
        ], 200);
    }

    /**
     * Submit a draft: Move valid entries to final attendance records and clear/mark draft.
     * POST /api/attendance/drafts/{id}/submit
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $draft = AttendanceDraft::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Draft not found or already processed.',
            ], 404);
        }

        DB::transaction(function () use ($draft, $request) {
            // 1. Insert parsed data into official attendance table (adjust table/model name as needed)
            foreach ($draft->parsed_data as $record) {
                DB::table('attendance_records')->insert([
                    'user_id'      => $request->user()->id,
                    'gathering_id' => $draft->gathering_id,
                    'student_id'   => $record['student_id'] ?? null,
                    'status'       => $record['status'] ?? 'present',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            // 2. Mark draft as submitted
            $draft->update(['status' => 'submitted']);

            // 3. Update related submission reminder status if applicable
            if ($draft->gathering_id) {
                Reminder::where('user_id', $request->user()->id)
                    ->where('gathering_id', $draft->gathering_id)
                    ->where('type', 'submission_deadline')
                    ->update(['status' => 'completed']);
            }
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Attendance record submitted successfully.',
        ], 200);
    }
}