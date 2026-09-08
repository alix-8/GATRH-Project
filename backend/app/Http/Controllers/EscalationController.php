<?php

namespace App\Http\Controllers;

use App\Models\Escalation;
use Illuminate\Http\Request;

class EscalationController extends Controller
{
    /**
     * Automatically updated via AttendanceRecordObserver :D
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Escalation::with('student')
            ->where('no_response_count', '>', 0);

        // Scope by program if user is a Program Head
        if ($user->role === 'program_head') {
            $programs = $user->assignedProgramCodes();

            $query->whereHas('student', function ($q) use ($programs) {
                $q->whereIn('program', $programs);
            });
        }

        // Optional filter by escalation level (e.g., Level 1, Level 2, Level 3)
        if ($request->has('level')) {
            $query->where('escalation_level', $request->level);
        }

        $escalations = $query->orderBy('no_response_count', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'count'  => $escalations->count(),
            'data'   => $escalations,
        ]);
    }

    /**
     * Display escalation details along with full attendance history.
     */
    public function show($id)
    {
        $escalation = Escalation::with(['student', 'student.attendanceRecords'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => $escalation,
        ]);
    }

    /**
     * Save intervention notes / advisor remarks for an escalated student.
     */
    public function update(Request $request, $id)
    {
        $escalation = Escalation::findOrFail($id);

        $validated = $request->validate([
            'notes'  => 'nullable|string|max:1000',
            'status' => 'nullable|in:pending,under_review,resolved',
        ]);

        $escalation->update($validated);

        return response()->json([
            'message'    => 'Intervention notes updated successfully.',
            'escalation' => $escalation->load('student'),
        ]);
    }
}