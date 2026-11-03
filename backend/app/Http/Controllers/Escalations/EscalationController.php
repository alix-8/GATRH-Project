<?php

namespace App\Http\Controllers\Escalations;

use App\Http\Controllers\Controller;

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

        $query = Escalation::query()->visibleTo($user)->with('student');

        $recipientLevel = match ($user->role) {
            'secretary' => 'Level 1',
            'program_head' => 'Level 2',
            'cm_coordinator', 'chancellor' => 'Level 3',
            default => null,
        };

        if ($request->has('level')) {
            if ($recipientLevel !== $request->level) {
                $query->whereRaw('1 = 0');
            }
        }

        $escalations = $query->orderBy('no_response_count', 'desc')->get();
        if ($recipientLevel !== null) {
            $escalations->each(fn ($escalation) => $escalation->forceFill(['escalation_level' => $recipientLevel]));
        }

        return response()->json([
            'status' => 'success',
            'count'  => $escalations->count(),
            'data'   => $escalations,
        ]);
    }

    /**
     * Display escalation details along with full attendance history.
     */
    public function show(Request $request, $id)
    {
        $escalation = Escalation::query()->visibleTo($request->user())
            ->with(['student', 'student.attendanceRecords'])
            ->findOrFail($id);
        $recipientLevel = match ($request->user()->role) {
            'secretary' => 'Level 1',
            'program_head' => 'Level 2',
            'cm_coordinator', 'chancellor' => 'Level 3',
            default => null,
        };
        if ($recipientLevel !== null) {
            $escalation->forceFill(['escalation_level' => $recipientLevel]);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $escalation,
        ]);
    }

    // /**
    //  * Save intervention notes / advisor remarks for an escalated student.
    //  */
    // public function update(Request $request, $id)
    // {
    //     $escalation = Escalation::findOrFail($id);

    //     abort_unless(
    //         Escalation::query()->visibleTo($request->user())->whereKey($escalation->id)->exists(),
    //         404
    //     );

    //     $validated = $request->validate([
    //         'notes'  => 'nullable|string|max:1000',
    //         'status' => 'nullable|in:pending,under_review,resolved',
    //     ]);

    //     $escalation->update($validated);

    //     return response()->json([
    //         'message'    => 'Intervention notes updated successfully.',
    //         'escalation' => $escalation->load('student'),
    //     ]);
    // }
}

