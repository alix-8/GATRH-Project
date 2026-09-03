<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Services\ReminderCountdownService;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    protected ReminderCountdownService $countdownService;

    public function __construct(ReminderCountdownService $countdownService)
    {
        $this->countdownService = $countdownService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

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
     * Store a newly created gathering reminder in storage.
     */
    public function storeGathering(Request $request)
    {
        $validated = $request->validate([
            'gathering_type' => 'required|in:pm,ws,tg,special',
            'program'        => 'required|string',
            'year_level'     => 'required|integer',
            'gathering_date' => 'required|date',
        ]);

        $reminder = Reminder::create([
            'user_id'        => $request->user()->id,
            'reminder_type'  => 'gathering',
            'gathering_type' => $validated['gathering_type'],
            'program'        => strtoupper($validated['program']),
            'year_level'     => $validated['year_level'],
            'gathering_date' => $validated['gathering_date'],
            'title'          => ucfirst($this->gatheringLabel($validated['gathering_type'])),
            'description'    => strtoupper($validated['program']) . '-' . $validated['year_level'],
            'due_date'       => $validated['gathering_date'],
            'status'         => 'upcoming',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Gathering reminder created.',
            'data'    => $reminder,
        ], 201);
    }

    private function gatheringLabel(string $type): string
    {
        return match ($type) {
            'pm'      => 'Prayer meeting',
            'ws'      => 'Worship service',
            'tg'      => 'Thanksgiving',
            'special' => 'Special gathering',
            default   => $type,
        };
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
}