<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Services\ReminderCountdownService;
use App\Services\GatheringService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReminderController extends Controller
{
    protected ReminderCountdownService $countdownService;
    protected GatheringService $gatheringService;

    public function __construct(
        ReminderCountdownService $countdownService,
        GatheringService $gatheringService
    )
    {
        $this->countdownService = $countdownService;
        $this->gatheringService = $gatheringService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        // Clean up gathering reminders left behind by attendance submissions
        // that were recorded before the submission endpoint began deleting them.
        Reminder::query()
            ->where('user_id', $userId)
            ->where('reminder_type', 'gathering')
            ->whereExists(function ($query) use ($userId) {
                $query->selectRaw('1')
                    ->from('attendance_records')
                    ->where('attendance_records.submitted_by', $userId)
                    ->whereColumn('attendance_records.gathering_type', 'reminders.gathering_type')
                    ->whereColumn('attendance_records.day', 'reminders.gathering_date');
            })
            ->delete();

        // Refresh statuses before listing, so upcoming/needs_action is always accurate
        $this->countdownService->statusSetter($userId);

        $status = $request->query('status');
        $query = $request->user()->reminders()->orderBy('due_date');

        if ($status) {
            $query->where('status', $status);
        }

        $reminders = $query->get();
        $countdowns = $this->countdownService->reminderCountdown($userId);

        $reminders->each(function ($reminder) use ($countdowns) {
            $reminder->countdown_text = $countdowns[$reminder->id] ?? null;
        });

        return response()->json([
            'status'  => 'success',
            'summary' => [
                'upcoming'     => $request->user()->reminders()->where('status', 'upcoming')->count(),
                'needs_action' => $request->user()->reminders()->where('status', 'needs_action')->count(),
                'completed'    => $request->user()->reminders()->where('status', 'completed')->count(),
            ],
            'data' => $reminders,
        ]); 
    }

    /**
     * Store a newly created reminder in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:20',
            'description' => 'nullable|string|max:50',
            'due_date'    => 'required|date|after_or_equal:today',
            'status'      => 'nullable|in:upcoming,needs_action,completed',
        ]);

        $reminder = Reminder::create([
             'user_id'       => $request->user()->id,
            'reminder_type' => 'custom',
            'title'         => $validated['title'],
            'description'   => $validated['description'] ?? null,
            'due_date'      => $validated['due_date'],
            'status'        => $validated['status'] ?? 'upcoming',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder created successfully.',
            'data'    => $reminder,
        ], 201);
    }

    /**
     * Display the specified reminder.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $reminder = Reminder::find($id);

        // Check for null FIRST, before touching any property on $reminder
        if (!$reminder) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reminder not found',
            ], 404);
        }

        // Now it's safe to access $reminder->user_id
        if ($reminder->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $reminder,
            'countdown' => app(ReminderCountdownService::class)->reminderCountdown($request->user()->id),
        ], 200);
    }

    /**
     * Update the specified reminder.
     */
    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        // Enforce ownership check
        if ($reminder->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $validated = $request->validate([
            'title'       => 'sometimes|required|string|max:20',
            'description' => 'nullable|string|max:50',
            'due_date'    => 'sometimes|required|date|after_or_equal:today',
            'status'      => 'sometimes|required|in:upcoming,needs_action,completed',
        ]);

        $reminder->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder updated successfully.',
            'data'    => $reminder,
        ], 200);
    }

    /**
     * Store a newly created gathering reminder in storage.
     */
    public function storeGathering(Request $request)
    {
        $validated = $request->validate([
            'gathering_type' => 'required|in:pm,ws,tg,special',
            'program'        => 'required|string',
            'year_level'     => 'required|integer',
            'gathering_date' => 'nullable|date|required_if:gathering_type,special',
        ]);

        $gatheringDate = $validated['gathering_date'] ?? $this->gatheringService->nextDate($validated['gathering_type']);

        if ($validated['gathering_type'] !== 'special' && ! $this->gatheringService->isScheduledDay($validated['gathering_type'], Carbon::parse($gatheringDate))) {
            return response()->json([
                'status'  => 'error',
                'message' => sprintf('%s reminders can only be scheduled on %s.',
                    strtoupper($validated['gathering_type']),
                    $validated['gathering_type'] === 'pm' ? 'Wednesday' : 'Saturday'
                ),
            ], 422);
        }

        $reminder = Reminder::create([
            'user_id'        => $request->user()->id,
            'reminder_type'  => 'gathering',
            'gathering_type' => $validated['gathering_type'],
            'program'        => strtoupper($validated['program']),
            'year_level'     => $validated['year_level'],
            'gathering_date' => $gatheringDate,
            'title'          => ucfirst($this->gatheringService->label($validated['gathering_type'])),
            'description'    => strtoupper($validated['program']) . '-' . $validated['year_level'],
            'due_date'       => $gatheringDate,
            'status'         => 'upcoming',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Gathering reminder created.',
            'data'    => $reminder,
        ], 201);
    }

    public function markCompleted(Request $request, $id)
    {
        $reminder = $request->user()->reminders()->findOrFail($id);

        $reminder->update([
            'status'       => 'completed',
            'updated_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder marked as completed.',
            'data'    => $reminder,
        ]);
    }

    /**
     * Remove the specified reminder from storage.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $reminder = Reminder::find($id);

        if (!$reminder) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reminder not found',
            ], 404);
        }

        if ($reminder->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $reminder->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Reminder deleted successfully.',
        ], 200);
    }
}
