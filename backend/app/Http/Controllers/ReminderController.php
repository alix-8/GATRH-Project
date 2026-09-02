<?php 

namespace App\Http\Controllers;

use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ReminderService;

class ReminderController extends Controller
{
    /**
     * Display a listing of reminders for the authenticated user.
     * Can filter by status (upcoming, needs_action, completed).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Reminder::where('user_id', $userId);

        // Optional query filter: /api/reminders?status=upcoming
        if ($request->has('status')) {
            $request->validate([
                'status' => 'in:upcoming,needs_action,completed',
            ]);
            $query->where('status', $request->status);
        }

        // Utilizes our compound index: (user_id, status, due_date)
        $reminders = $query->orderBy('due_date', 'asc')->get();
        $countdown = app(ReminderService::class)->ReminderCountdown($userId);
        app(ReminderService::class)->StatusSetter($userId);
        $data = $reminders->map(function ($reminder) use ($countdown) {
            return [
                'id'          => $reminder->id,
                'title'       => $reminder->title,
                'description' => $reminder->description,
                'due_date'    => $reminder->due_date,
                'status'      => $reminder->status,
                'countdown'   => $countdown[$reminder->id] ?? null, // Get countdown for this reminder
            ];
        });

        return response()->json([
            'status' => 'success',
            'count'  => $reminders->count(),
            'data'   => $data,
        ], 200);
    }

    /**
     * Store a newly created reminder in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'required|date',
            'status'      => 'nullable|in:upcoming,needs_action,completed',
        ]);

        $reminder = Reminder::create([
            'user_id'     => $request->user()->id,
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date'    => $validated['due_date'],
            'status'      => $validated['status'] ?? 'upcoming',
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
            'countdown' => app(ReminderService::class)->ReminderCountdown($request->user()->id),
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
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'sometimes|required|date',
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

        // Enforce ownership check
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